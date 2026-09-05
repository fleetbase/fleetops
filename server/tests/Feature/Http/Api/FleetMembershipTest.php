<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FleetController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

/**
 * The membership pivots carry soft deletes, so "assign, remove, assign again"
 * has to reach a real table to prove anything: the interesting cases are a row
 * that already exists, a row that was removed and is being restored, and a
 * removal of something that was never there. All three are invisible to a fake.
 */
function fleetopsMembershipDatabase(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    // A dispatcher is required, not optional: without one the uuid hooks never
    // fire and — more importantly here — `FleetVehicle::creating()` silently
    // registers nothing, so a race test would pass without ever racing.
    // Memoised, because a fresh dispatcher drops the hooks of models already
    // booted in this process.
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }

    // The dispatcher brings the lifecycle observers with it — the HTTP cache
    // observer calls the `responsecache` binding on every create and delete —
    // so satisfy it rather than letting an unrelated facade fail the assertion.
    if (!app()->bound('responsecache')) {
        app()->instance('responsecache', new class {
            public function clear(): void
            {
            }

            public function forget($uris): void
            {
            }

            public function __call($method, $arguments)
            {
                return null;
            }
        });
    }

    config()->set('activitylog.enabled', false);
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());

    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();

    foreach (['fleet_vehicles' => 'vehicle_uuid', 'fleet_drivers' => 'driver_uuid'] as $table => $subjectColumn) {
        $schema->create($table, function ($blueprint) use ($table, $subjectColumn) {
            $blueprint->increments('id');
            $blueprint->string('uuid')->nullable();
            $blueprint->string('_key')->nullable();
            $blueprint->string('fleet_uuid')->nullable();
            $blueprint->string($subjectColumn)->nullable();
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();

            // The same composite index the migration adds. Soft-deleted rows are
            // deliberately inside the key: a removed membership is restored on
            // re-assignment rather than replaced, so its key must stay taken.
            $blueprint->unique(['fleet_uuid', $subjectColumn], $table . '_pair_unique');
        });
    }

    return $connection;
}

function fleetopsMembershipController(): FleetController
{
    return new FleetController();
}

function fleetopsMembershipInvoke(string $method, ...$arguments)
{
    $controller = fleetopsMembershipController();
    $reflection = new ReflectionMethod(FleetController::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($controller, ...$arguments);
}

function fleetopsMembershipFleet(string $uuid, string $publicId): Fleet
{
    $fleet = new Fleet();
    $fleet->setRawAttributes(['uuid' => $uuid, 'public_id' => $publicId], true);

    return $fleet;
}

function fleetopsMembershipVehicle(string $uuid, string $publicId): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes(['uuid' => $uuid, 'public_id' => $publicId], true);

    return $vehicle;
}

function fleetopsMembershipDriver(string $uuid, string $publicId, ?string $vehicleUuid = null): Driver
{
    $driver = new Driver();
    $driver->setRawAttributes([
        'uuid'         => $uuid,
        'public_id'    => $publicId,
        'vehicle_uuid' => $vehicleUuid,
    ], true);

    return $driver;
}

test('assigning a vehicle to a fleet is idempotent and creates no duplicate membership', function () {
    $connection = fleetopsMembershipDatabase();
    $fleet      = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $vehicle    = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');

    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);

    $rows = $connection->table('fleet_vehicles')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->fleet_uuid)->toBe('fleet-uuid-1')
        ->and($rows[0]->vehicle_uuid)->toBe('vehicle-uuid-1')
        ->and($rows[0]->deleted_at)->toBeNull();
});

test('removing a vehicle from a fleet is a safe no-op when repeated', function () {
    $connection = fleetopsMembershipDatabase();
    $fleet      = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $vehicle    = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');

    // Removing before anything was ever assigned must not raise.
    fleetopsMembershipInvoke('removeVehicleFromFleet', $fleet, $vehicle);

    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('removeVehicleFromFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('removeVehicleFromFleet', $fleet, $vehicle);

    expect(FleetVehicle::where(['fleet_uuid' => 'fleet-uuid-1', 'vehicle_uuid' => 'vehicle-uuid-1'])->count())->toBe(0)
        ->and($connection->table('fleet_vehicles')->whereNotNull('deleted_at')->count())->toBe(1);
});

test('a soft deleted vehicle membership is restored rather than duplicated', function () {
    $connection = fleetopsMembershipDatabase();
    $fleet      = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $vehicle    = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');

    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('removeVehicleFromFleet', $fleet, $vehicle);
    fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);

    // One row, live again — not a second row shadowing a tombstone.
    expect($connection->table('fleet_vehicles')->count())->toBe(1)
        ->and(FleetVehicle::where(['fleet_uuid' => 'fleet-uuid-1', 'vehicle_uuid' => 'vehicle-uuid-1'])->count())->toBe(1);
});

test('assigning a driver to a fleet is idempotent and restores a removed membership', function () {
    $connection = fleetopsMembershipDatabase();
    $fleet      = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $driver     = fleetopsMembershipDriver('driver-uuid-1', 'driver_123');

    fleetopsMembershipInvoke('assignDriverToFleet', $fleet, $driver);
    fleetopsMembershipInvoke('assignDriverToFleet', $fleet, $driver);
    fleetopsMembershipInvoke('removeDriverFromFleet', $fleet, $driver);
    fleetopsMembershipInvoke('removeDriverFromFleet', $fleet, $driver);
    fleetopsMembershipInvoke('assignDriverToFleet', $fleet, $driver);

    expect($connection->table('fleet_drivers')->count())->toBe(1)
        ->and(FleetDriver::where(['fleet_uuid' => 'fleet-uuid-1', 'driver_uuid' => 'driver-uuid-1'])->count())->toBe(1);
});

test('a fleet membership change leaves the driver its vehicle and its other fleets', function () {
    $connection = fleetopsMembershipDatabase();
    $driver     = fleetopsMembershipDriver('driver-uuid-1', 'driver_123', 'vehicle-uuid-9');
    $fleetOne   = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_one');
    $fleetTwo   = fleetopsMembershipFleet('fleet-uuid-2', 'fleet_two');

    fleetopsMembershipInvoke('assignDriverToFleet', $fleetOne, $driver);
    fleetopsMembershipInvoke('assignDriverToFleet', $fleetTwo, $driver);
    fleetopsMembershipInvoke('removeDriverFromFleet', $fleetOne, $driver);

    $live = FleetDriver::where('driver_uuid', 'driver-uuid-1')->pluck('fleet_uuid')->all();

    expect($live)->toBe(['fleet-uuid-2'])
        // The pivot is the only thing touched: the driver's current vehicle is
        // untouched and the driver itself is still there.
        ->and($driver->vehicle_uuid)->toBe('vehicle-uuid-9')
        ->and($connection->table('fleet_drivers')->count())->toBe(2);
});

test('a vehicle stays in its other fleets when removed from one', function () {
    fleetopsMembershipDatabase();
    $vehicle  = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');
    $fleetOne = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_one');
    $fleetTwo = fleetopsMembershipFleet('fleet-uuid-2', 'fleet_two');

    fleetopsMembershipInvoke('assignVehicleToFleet', $fleetOne, $vehicle);
    fleetopsMembershipInvoke('assignVehicleToFleet', $fleetTwo, $vehicle);
    fleetopsMembershipInvoke('removeVehicleFromFleet', $fleetTwo, $vehicle);

    expect(FleetVehicle::where('vehicle_uuid', 'vehicle-uuid-1')->pluck('fleet_uuid')->all())->toBe(['fleet-uuid-1']);
});

test('the composite index refuses a duplicate membership pair outright', function () {
    $connection = fleetopsMembershipDatabase();

    $connection->table('fleet_vehicles')->insert([
        'uuid'         => 'pivot-1',
        'fleet_uuid'   => 'fleet-uuid-1',
        'vehicle_uuid' => 'vehicle-uuid-1',
    ]);

    // The controller's read-then-write is idempotent only against itself. This
    // is the guarantee underneath it: even a direct insert cannot produce a
    // second logical membership.
    expect(fn () => $connection->table('fleet_vehicles')->insert([
        'uuid'         => 'pivot-2',
        'fleet_uuid'   => 'fleet-uuid-1',
        'vehicle_uuid' => 'vehicle-uuid-1',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);

    // A tombstone still occupies the key, which is what forces the restore path.
    $connection->table('fleet_vehicles')->where('uuid', 'pivot-1')->update(['deleted_at' => now()]);

    expect(fn () => $connection->table('fleet_vehicles')->insert([
        'uuid'         => 'pivot-3',
        'fleet_uuid'   => 'fleet-uuid-1',
        'vehicle_uuid' => 'vehicle-uuid-1',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('a competing request that wins the race is adopted rather than reported as an error', function () {
    $connection = fleetopsMembershipDatabase();
    $fleet      = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $vehicle    = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');

    // The row appears between this request reading an empty table and writing to
    // it — the interleaving a retrying importer actually produces. Inserted
    // through the query builder so no model event recurses.
    $inserted = false;
    FleetVehicle::creating(function () use ($connection, &$inserted) {
        if ($inserted) {
            return;
        }

        $inserted = true;
        $connection->table('fleet_vehicles')->insert([
            'uuid'         => 'winner',
            'fleet_uuid'   => 'fleet-uuid-1',
            'vehicle_uuid' => 'vehicle-uuid-1',
            'deleted_at'   => now(),
        ]);
    });

    try {
        fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle);
    } finally {
        FleetVehicle::flushEventListeners();
    }

    // The loser adopts the winner's row and restores it, so both callers get the
    // same successful answer and there is still exactly one membership.
    expect($connection->table('fleet_vehicles')->count())->toBe(1)
        ->and(FleetVehicle::where([
            'fleet_uuid'   => 'fleet-uuid-1',
            'vehicle_uuid' => 'vehicle-uuid-1',
        ])->count())->toBe(1);
});

test('a duplicate key that is not this membership is not swallowed', function () {
    $connection = fleetopsMembershipDatabase();
    $connection->statement('create unique index fleet_vehicles_uuid_unique on fleet_vehicles (uuid)');

    $fleet   = fleetopsMembershipFleet('fleet-uuid-1', 'fleet_123');
    $vehicle = fleetopsMembershipVehicle('vehicle-uuid-1', 'vehicle_123');

    // A violation on a different key means something else is wrong. Reporting it
    // as a successful assignment would hide a real failure behind a 200.
    $collided = false;
    FleetVehicle::creating(function ($membership) use ($connection, &$collided) {
        if ($collided) {
            return;
        }

        $collided = true;

        // Pin the uuid and take it first, so the save collides on the uuid index
        // rather than on the membership pair.
        $membership->uuid = 'collides-on-uuid';
        $connection->table('fleet_vehicles')->insert([
            'uuid'         => 'collides-on-uuid',
            'fleet_uuid'   => 'fleet-uuid-9',
            'vehicle_uuid' => 'vehicle-uuid-9',
        ]);
    });

    try {
        expect(fn () => fleetopsMembershipInvoke('assignVehicleToFleet', $fleet, $vehicle))
            ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
    } finally {
        FleetVehicle::flushEventListeners();
    }
});
