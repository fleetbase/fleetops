<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController;
use Fleetbase\FleetOps\Http\Filter\VehicleFilter;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the API VehicleController tracking surface: the geofence crossing
 * processor (entered/exited branches with triggers and dwell scheduling), the
 * position-update section of track(), and the real bodies of the protected
 * lookup, query, resource, and response helpers.
 */
if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function event($event = null) { \FleetOpsApiVehicleTrackingRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\broadcast')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function broadcast($event = null) { \FleetOpsApiVehicleTrackingRecorder::$broadcasts[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new VehicleController());
}

if (!Request::hasMacro('or')) {
    Request::macro('or', function (array $params = [], $default = null) {
        foreach ($params as $param) {
            if ($this->has($param)) {
                return $this->input($param);
            }
        }

        return $default;
    });
}

class FleetOpsApiVehicleTrackingRecorder
{
    public static array $events     = [];
    public static array $broadcasts = [];
}

class FleetOpsApiVehicleTrackingVehicleFake extends Vehicle
{
    protected $guarded         = [];
    public $exists             = true;
    public array $quietUpdates = [];
    public array $positions    = [];

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;

        return true;
    }

    public function createPosition(array $attributes = [], EloquentModel|string|null $destination = null): ?Fleetbase\FleetOps\Models\Position
    {
        $this->positions[] = $attributes;

        return null;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsApiVehicleTrackingProbe extends VehicleController
{
    public ?Vehicle $vehicle = null;

    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(VehicleController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function findVehicle(string $id): Vehicle
    {
        if ($this->vehicle) {
            return $this->vehicle;
        }

        return parent::findVehicle($id);
    }
}

function fleetopsApiVehicleTrackingBoot(): SQLiteConnection
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'vehicles'                => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'driver_uuid', 'vendor_uuid', 'status', 'make', 'model', 'plate_number', 'location', 'latitude', 'longitude', 'altitude', 'heading', 'speed', 'avatar_url', 'type', 'year', 'trim', 'vin', 'online'],
        'drivers'                 => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status'],
        'users'                   => ['uuid', 'public_id', 'company_uuid'],
        'vendors'                 => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name'],
        'vehicle_geofence_states' => ['vehicle_uuid', 'geofence_uuid', 'geofence_type', 'is_inside', 'entered_at', 'exited_at', 'dwell_job_id'],
        'directives'              => ['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns, $table) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();

            if ($table === 'vehicle_geofence_states') {
                $blueprint->unique(['vehicle_uuid', 'geofence_uuid']);
            }
        });
    }

    session(['company' => 'company-1']);
    FleetOpsApiVehicleTrackingRecorder::$events     = [];
    FleetOpsApiVehicleTrackingRecorder::$broadcasts = [];

    return $connection;
}

function fleetopsApiVehicleTrackingGeofence(array $attributes = []): stdClass
{
    return (object) array_merge([
        'uuid'                    => 'geofence-1',
        'trigger_on_entry'        => true,
        'trigger_on_exit'         => true,
        'dwell_threshold_minutes' => 0,
    ], $attributes);
}

/*
|--------------------------------------------------------------------------
| Geofence crossing processor
|--------------------------------------------------------------------------
*/

test('entered crossings upsert state and fire entry events', function () {
    $connection                   = fleetopsApiVehicleTrackingBoot();
    DispatchRecorder::$dispatched = [];
    $vehicle                      = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1'], true);

    (new FleetOpsApiVehicleTrackingProbe())->callProtected('processVehicleGeofenceCrossings', [
        $vehicle,
        new Point(1.0, 2.0),
        [[
            'type'          => 'entered',
            'geofence'      => fleetopsApiVehicleTrackingGeofence(),
            'geofence_type' => 'service-area',
        ]],
    ]);

    expect($connection->table('vehicle_geofence_states')->count())->toBe(1)
        ->and($connection->table('vehicle_geofence_states')->value('is_inside'))->toBe('1')
        ->and(FleetOpsApiVehicleTrackingRecorder::$events)->toHaveCount(1)
        ->and(DispatchRecorder::$dispatched)->toBe([]);
});

test('entered crossings with a dwell threshold schedule a dwell check job', function () {
    $connection                   = fleetopsApiVehicleTrackingBoot();
    DispatchRecorder::$dispatched = [];
    $vehicle                      = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1'], true);

    (new FleetOpsApiVehicleTrackingProbe())->callProtected('processVehicleGeofenceCrossings', [
        $vehicle,
        new Point(1.0, 2.0),
        [[
            'type'          => 'entered',
            'geofence'      => fleetopsApiVehicleTrackingGeofence(['trigger_on_entry' => false, 'dwell_threshold_minutes' => 5]),
            'geofence_type' => 'zone',
        ]],
    ]);

    expect(DispatchRecorder::$dispatched)->toHaveCount(1)
        ->and(DispatchRecorder::$dispatched[0]['job'])->toBe(CheckGeofenceDwell::class)
        ->and(FleetOpsApiVehicleTrackingRecorder::$events)->toBe([]);
});

test('entered crossings without triggers or dwell thresholds are skipped', function () {
    $connection = fleetopsApiVehicleTrackingBoot();
    $vehicle    = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1'], true);

    (new FleetOpsApiVehicleTrackingProbe())->callProtected('processVehicleGeofenceCrossings', [
        $vehicle,
        new Point(1.0, 2.0),
        [[
            'type'          => 'entered',
            'geofence'      => fleetopsApiVehicleTrackingGeofence(['trigger_on_entry' => false, 'dwell_threshold_minutes' => null]),
            'geofence_type' => 'zone',
        ]],
    ]);

    expect($connection->table('vehicle_geofence_states')->count())->toBe(0);
});

test('exited crossings compute dwell time and fire exit events', function () {
    $connection = fleetopsApiVehicleTrackingBoot();
    $connection->table('vehicle_geofence_states')->insert([
        'vehicle_uuid'  => 'vehicle-1',
        'geofence_uuid' => 'geofence-1',
        'geofence_type' => 'zone',
        'is_inside'     => '1',
        'entered_at'    => now()->subMinutes(30)->toDateTimeString(),
    ]);
    $vehicle = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1'], true);

    (new FleetOpsApiVehicleTrackingProbe())->callProtected('processVehicleGeofenceCrossings', [
        $vehicle,
        new Point(1.0, 2.0),
        [[
            'type'          => 'exited',
            'geofence'      => fleetopsApiVehicleTrackingGeofence(),
            'geofence_type' => 'zone',
        ]],
    ]);

    $state = $connection->table('vehicle_geofence_states')->first();
    expect($state->is_inside)->toBe('0')
        ->and($state->exited_at)->not->toBeNull()
        ->and(FleetOpsApiVehicleTrackingRecorder::$events)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| track() position update path
|--------------------------------------------------------------------------
*/

test('track updates the vehicle position and broadcasts the location change', function () {
    fleetopsApiVehicleTrackingBoot();
    $vehicle = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1'], true);

    $probe          = new FleetOpsApiVehicleTrackingProbe();
    $probe->vehicle = $vehicle;

    $request  = Request::create('/v1/vehicles/vehicle-1/track', 'POST', [
        'latitude'  => '1.3521',
        'longitude' => '103.8198',
        'heading'   => '90',
        'speed'     => '45',
    ]);
    $response = $probe->track('vehicle-1', $request);

    expect($response)->toBeInstanceOf(VehicleResource::class)
        ->and($vehicle->quietUpdates)->toHaveCount(1)
        ->and($vehicle->quietUpdates[0]['latitude'])->toBe(1.3521)
        ->and($vehicle->positions)->toHaveCount(1)
        ->and(FleetOpsApiVehicleTrackingRecorder::$broadcasts)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Protected helper bodies
|--------------------------------------------------------------------------
*/

test('lookup create and response helpers execute their real implementations', function () {
    $connection = fleetopsApiVehicleTrackingBoot();
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'internal_id' => 'VH-1', 'company_uuid' => 'company-1']);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'internal_id' => 'DR-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'public_id' => 'vendor_test', 'company_uuid' => 'company-1', 'name' => 'Acme']);

    $probe = new FleetOpsApiVehicleTrackingProbe();

    expect($probe->callProtected('findVehicle', ['vehicle_test']))->toBeInstanceOf(Vehicle::class)
        ->and($probe->callProtected('findDriver', ['driver_test']))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('getVendorUuid', ['vendors', ['public_id' => 'vendor_test']]))->toBe('vendor-1');

    expect(fn () => $probe->callProtected('findVehicle', ['missing']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $probe->callProtected('findDriver', ['missing']))->toThrow(ModelNotFoundException::class);

    $created = $probe->callProtected('createVehicle', [['company_uuid' => 'company-1', 'make' => 'Volvo', 'model' => 'FH16']]);
    expect($created)->toBeInstanceOf(Vehicle::class)
        ->and($connection->table('vehicles')->where('make', 'Volvo')->count())->toBe(1);

    $vehicle = $probe->callProtected('findVehicle', ['vehicle_test']);
    expect($probe->callProtected('vehicleResource', [$vehicle]))->toBeInstanceOf(VehicleResource::class)
        ->and($probe->callProtected('deletedVehicleResource', [$vehicle]))->toBeInstanceOf(DeletedResource::class)
        ->and($probe->callProtected('vehicleResourceCollection', [[$vehicle]]))
        ->toBeInstanceOf(Illuminate\Http\Resources\Json\ResourceCollection::class);

    $json = $probe->callProtected('jsonResponse', [['ok' => true], 201]);
    expect($json)->toBeInstanceOf(JsonResponse::class)
        ->and($json->getStatusCode())->toBe(201);

    $error = $probe->callProtected('apiError', ['vehicle not found', 404]);
    expect($error->getStatusCode())->toBe(404)
        ->and($error->getData(true))->toBe(['error' => 'vehicle not found']);
});

test('query vehicles helper filters by vendor through the request pipeline', function () {
    $connection = fleetopsApiVehicleTrackingBoot();
    $connection->table('vehicles')->insert([
        ['uuid' => 'vehicle-1', 'public_id' => 'vehicle_test', 'company_uuid' => 'company-1', 'vendor_uuid' => 'vendor-1'],
        ['uuid' => 'vehicle-2', 'public_id' => 'vehicle_other', 'company_uuid' => 'company-1', 'vendor_uuid' => 'vendor-2'],
    ]);
    $connection->table('vendors')->insert([
        ['uuid' => 'vendor-1', 'public_id' => 'vendor_test', 'internal_id' => 'ACME-1', 'company_uuid' => 'company-1', 'name' => 'Acme'],
        ['uuid' => 'vendor-2', 'public_id' => 'vendor_other', 'internal_id' => 'OTHER-1', 'company_uuid' => 'company-1', 'name' => 'Other'],
    ]);

    $probe   = new FleetOpsApiVehicleTrackingProbe();
    $request = function (array $parameters, string $uri = 'v1/vehicles') {
        $request = Request::create('/' . $uri, 'GET', $parameters);
        $store   = app('session.store');
        $store->put('company', 'company-1');
        $request->setLaravelSession($store);
        $request->setRouteResolver(fn () => new class($uri) {
            public function __construct(private string $uri)
            {
            }

            public function getAction($key = null)
            {
                return VehicleController::class . '@query';
            }

            public function getActionMethod()
            {
                return 'query';
            }

            public function uri()
            {
                return $this->uri;
            }

            public function getName()
            {
                return 'api.v1.vehicles.query';
            }

            public function parameters()
            {
                return [];
            }
        });

        return $request;
    };

    $query = fn (array $parameters) => $probe->callProtected('queryVehicles', [$request($parameters)]);

    // no vendor constraint returns every vehicle for the company
    expect($query([])->count())->toBe(2);

    // the public API identifies a vendor by its public id or internal id
    expect($query(['vendor' => 'vendor_test'])->pluck('uuid')->all())->toBe(['vehicle-1'])
        ->and($query(['vendor' => 'ACME-1'])->pluck('uuid')->all())->toBe(['vehicle-1'])
        ->and($query(['vendor' => 'vendor_other'])->pluck('uuid')->all())->toBe(['vehicle-2']);

    // a uuid is not a public identifier, and unknown identifiers match nothing
    expect($query(['vendor' => 'vendor-1'])->count())->toBe(0)
        ->and($query(['vendor' => 'nope'])->count())->toBe(0);

    // internal routes keep accepting the uuid the management console sends
    $internal = fn (string $vendor) => (new VehicleFilter($request(['vendor' => $vendor], 'int/v1/vehicles')))
        ->apply(Vehicle::query())
        ->pluck('uuid')
        ->all();

    expect($internal('vendor-1'))->toBe(['vehicle-1'])
        ->and($internal('vendor_test'))->toBe(['vehicle-1']);
});

test('track appends current order context and reports geofence failures to sentry', function () {
    fleetopsApiVehicleTrackingBoot();

    $place = new Fleetbase\FleetOps\Models\Place();
    $place->setRawAttributes(['uuid' => 'place-dest-1'], true);

    $payload = new class extends Fleetbase\FleetOps\Models\Payload {
        public ?Fleetbase\FleetOps\Models\Place $waypointFake = null;

        public function getPickupOrCurrentWaypoint(): ?Fleetbase\FleetOps\Models\Place
        {
            return $this->waypointFake;
        }
    };
    $payload->waypointFake = $place;

    $order = new Fleetbase\FleetOps\Models\Order();
    $order->setRawAttributes(['uuid' => 'order-track-1', 'company_uuid' => 'company-1'], true);
    $order->setRelation('payload', $payload);

    $driver = new class extends Driver {
        public ?Fleetbase\FleetOps\Models\Order $currentOrderFake = null;

        public function getCurrentOrder(): ?Fleetbase\FleetOps\Models\Order
        {
            return $this->currentOrderFake;
        }
    };
    $driver->setRawAttributes(['uuid' => 'driver-track-1', 'company_uuid' => 'company-1'], true);
    $driver->currentOrderFake = $order;

    $vehicle = new FleetOpsApiVehicleTrackingVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-track-2', 'company_uuid' => 'company-1'], true);
    $vehicle->setRelation('driver', $driver);

    // Geofence detection failures surface through the sentry client
    $sentry = new class {
        public array $captured = [];

        public function captureException($exception)
        {
            $this->captured[] = $exception->getMessage();
        }
    };
    app()->instance('sentry', $sentry);
    app()->bind(Fleetbase\FleetOps\Support\GeofenceIntersectionService::class, function () {
        return new class {
            public function detectVehicleCrossings($vehicle, $location)
            {
                throw new RuntimeException('geofence backend offline');
            }
        };
    });

    $probe          = new FleetOpsApiVehicleTrackingProbe();
    $probe->vehicle = $vehicle;

    $response = $probe->track('vehicle-track-2', Request::create('/v1/vehicles/vehicle-track-2/track', 'POST', [
        'latitude'  => '1.30',
        'longitude' => '103.80',
    ]));

    expect($response)->toBeInstanceOf(VehicleResource::class)
        ->and($vehicle->quietUpdates[0]['order_uuid'])->toBe('order-track-1')
        ->and($vehicle->quietUpdates[0]['destination_uuid'])->toBe('place-dest-1')
        ->and($sentry->captured)->toBe(['geofence backend offline']);
});
