<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\VehicleDevice;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;

/**
 * Covers the lookup fallbacks models take when a relation is not already
 * loaded, plus the pass-through arms of the attribute mutators. Behaviour
 * tests set the relations up front, so the queries behind them never run.
 */
function fleetopsLookupSeamBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema  = $connection->getSchemaBuilder();
    $columns = ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'device_uuid', 'name', 'type', 'status', 'tracking_number', 'region', 'owner_uuid', '_key'];
    foreach (['users', 'drivers', 'vehicles', 'vehicle_devices', 'tracking_numbers'] as $table) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-lookup-1']);

    return $connection;
}

test('drivers fall back to a direct user lookup when the relation is empty', function () {
    $connection = fleetopsLookupSeamBoot();
    $connection->table('users')->insert([
        'uuid'         => '33333333-3333-4333-8333-333333333333',
        'company_uuid' => 'company-lookup-1',
        'name'         => 'Linked Account',
    ]);

    // The relation is scoped and column-selective, so it can come back empty
    // for a row the plain uuid lookup still finds — that mismatch is the whole
    // reason the fallback exists.
    $driver = new class extends Driver {
        public function user()
        {
            return $this->belongsTo(Fleetbase\Models\User::class)->whereRaw('1 = 0');
        }
    };
    $driver->setRawAttributes([
        'uuid'      => 'driver-lookup-1',
        'user_uuid' => '33333333-3333-4333-8333-333333333333',
    ], true);

    expect($driver->getUser()?->name)->toBe('Linked Account');

    // A non-uuid user reference is not worth a query
    $unlinked = new class extends Driver {
        public function user()
        {
            return $this->belongsTo(Fleetbase\Models\User::class)->whereRaw('1 = 0');
        }
    };
    $unlinked->setRawAttributes(['uuid' => 'driver-lookup-2', 'user_uuid' => 'not-a-uuid'], true);
    expect($unlinked->getUser())->toBeNull();
});

test('vehicle devices fall back to a direct vehicle lookup by uuid', function () {
    $connection = fleetopsLookupSeamBoot();
    $connection->table('vehicles')->insert([
        'uuid'         => 'vehicle-lookup-1',
        'public_id'    => 'vehicle_lookupone',
        'company_uuid' => 'company-lookup-1',
    ]);

    $device = new class extends VehicleDevice {
        public function vehicle()
        {
            return $this->belongsTo(Vehicle::class)->whereRaw('1 = 0');
        }
    };
    $device->setRawAttributes(['uuid' => 'vd-lookup-1', 'vehicle_uuid' => 'vehicle-lookup-1'], true);

    // The relation comes back empty; the uuid still resolves the vehicle, and
    // the callback only fires once something was found.
    $seen = [];
    expect($device->getVehicle(function ($vehicle) use (&$seen) { $seen[] = $vehicle->public_id; }))
        ->toBeInstanceOf(Vehicle::class)
        ->and($seen)->toBe(['vehicle_lookupone']);
});

test('known equipable aliases are normalized and unrecognised types are stored verbatim', function () {
    $equipment = new Equipment();

    // Known short names map onto their fully qualified model class
    $equipment->equipable_type = 'vehicle';
    expect($equipment->getAttributes()['equipable_type'])->toContain('Vehicle');

    $equipment->equipable_type = 'trailer';
    expect($equipment->getAttributes()['equipable_type'])->toBe(Trailer::class);

    // Anything else is kept as given rather than silently dropped
    $equipment->equipable_type = 'cargo-bay';
    expect($equipment->getAttributes()['equipable_type'])->toBe('cargo-bay');
});

test('tracking number generation redraws when the first number is already taken', function () {
    $connection = fleetopsLookupSeamBoot();
    $connection->table('tracking_numbers')->insert([
        'uuid'            => 'tn-lookup-1',
        'company_uuid'    => 'company-lookup-1',
        'tracking_number' => 'SGTAKEN0001',
    ]);

    // Random draws practically never collide, so the redraw is only reachable
    // by pinning the sequence.
    $probe = new class extends TrackingNumber {
        public static array $sequence = ['SGTAKEN0001', 'SGFREE00002'];

        public static function generateTrackingNumber($region = 'SG', $length = 10): string
        {
            return array_shift(static::$sequence);
        }
    };

    expect($probe::generateNumber('SG', 10))->toBe('SGFREE00002');
});
