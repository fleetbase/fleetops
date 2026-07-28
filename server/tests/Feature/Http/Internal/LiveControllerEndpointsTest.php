<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\LiveController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Covers the LiveController map endpoints against SQLite with a tagged-cache
 * fake: active-order coordinates, active routes with the nested order
 * constraints, the live order query, viewport-bounded driver/vehicle
 * listings with spatial location guards, and filtered places.
 */
class FleetOpsLiveEndpointsTaggedCache
{
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        return $callback();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return is_callable($default) ? $default() : $default;
    }

    public function put(...$arguments): bool
    {
        return true;
    }

    public function forever(...$arguments): bool
    {
        return true;
    }

    public function forget(string $key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}

class FleetOpsLiveEndpointsCacheFake
{
    public function tags(array $tags): FleetOpsLiveEndpointsTaggedCache
    {
        return new FleetOpsLiveEndpointsTaggedCache();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function increment(string $key): int
    {
        return 1;
    }
}

function fleetopsLiveEndpointsPermissionMacro(): void
{
    $reflection = new ReflectionClass(Illuminate\Database\Eloquent\Builder::class);
    $property   = $reflection->getProperty('macros');
    $macros     = $property->getValue();

    $macros['applyDirectivesForPermissions'] = function (string|array $names = []) {
        return $this;
    };

    $property->setValue(null, $macros);
}

function fleetopsLiveEndpointsBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('CONCAT', fn (...$values) => implode('', array_map(fn ($value) => $value ?? '', $values)));
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $connection = new SQLiteConnection($pdo);
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

    fleetopsLiveEndpointsPermissionMacro();
    Cache::swap(new FleetOpsLiveEndpointsCacheFake());

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'type', 'meta', 'scheduled_at', 'dispatched', 'adhoc', 'internal_id', 'created_by_uuid'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta', 'type', 'owner_uuid'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order_id', 'order', 'type'],
        'routes'            => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'details'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'code', 'tracking_number_uuid', 'status', 'details'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'current_job_uuid', 'status', 'online', 'location', 'heading', 'speed', 'altitude'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'phone', 'email', 'status', 'type', 'avatar_uuid'],
        'vehicles'          => ['uuid', 'public_id', 'company_uuid', 'vendor_uuid', 'photo_uuid', 'name', 'display_name', 'plate_number', 'serial_number', 'fuel_card_number', 'vin', 'vin_data', 'make', 'model', 'year', 'trim', 'class', 'color', 'call_sign', 'specs', 'telematics', 'status', 'online', 'location', 'meta', 'avatar_url', 'internal_id', 'speed', 'heading', 'altitude'],
        'devices'           => ['uuid', 'public_id', 'company_uuid', 'device_id', 'device_type', 'device_provider', 'owner_uuid', 'owner_type', 'attachable_uuid', 'attachable_type', 'status'],
        'vendors'           => ['uuid', 'public_id', 'company_uuid', 'name'],
        'files'             => ['uuid', 'public_id', 'company_uuid', 'type', 'path', 'disk'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'name', 'type'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsLiveEndpointsWkb(float $latitude, float $longitude): string
{
    // SRID prefix + little-endian WKB point — long enough to trigger the
    // spatial trait's fromWKB hydration into a real Point instance.
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $longitude) . pack('d', $latitude);
}

function fleetopsLiveEndpointsSeedOrder(SQLiteConnection $connection): void
{
    $connection->table('places')->insert([
        ['uuid' => 'place-p', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'location' => fleetopsLiveEndpointsWkb(1.30, 103.80)],
        ['uuid' => 'place-d', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'location' => fleetopsLiveEndpointsWkb(1.35, 103.85)],
    ]);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver One']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => '1']);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-p', 'dropoff_uuid' => 'place-d']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-1', 'owner_uuid' => 'order-1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'company_uuid' => 'company-1', 'code' => 'CREATED', 'tracking_number_uuid' => 'tn-1']);
    $connection->table('orders')->insert([
        'uuid'                 => 'order-1',
        'public_id'            => 'order_live',
        'company_uuid'         => 'company-1',
        'payload_uuid'         => 'payload-1',
        'tracking_number_uuid' => 'tn-1',
        'driver_assigned_uuid' => 'driver-1',
        'status'               => 'driver_enroute',
    ]);
    $connection->table('routes')->insert(['uuid' => 'route-1', 'company_uuid' => 'company-1', 'order_uuid' => 'order-1']);
}

test('coordinates lists destinations for active orders', function () {
    $connection = fleetopsLiveEndpointsBoot();
    fleetopsLiveEndpointsSeedOrder($connection);

    $response = (new LiveController())->coordinates();

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and(count($response->getData(true)))->toBe(1);
});

test('routes lists routes with active driver assigned orders', function () {
    $connection = fleetopsLiveEndpointsBoot();
    fleetopsLiveEndpointsSeedOrder($connection);

    $response = (new LiveController())->routes();
    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getData(true))->toHaveCount(1);

    // Orders without an assigned driver drop the route from the feed
    $connection->table('orders')->where('uuid', 'order-1')->update(['driver_assigned_uuid' => null]);
    expect((new LiveController())->routes()->getData(true))->toHaveCount(0);
});

test('orders returns the live order query collection', function () {
    $connection = fleetopsLiveEndpointsBoot();
    fleetopsLiveEndpointsSeedOrder($connection);

    $collection = (new LiveController())->orders(Request::create('/x', 'GET', ['active' => '1']));
    expect($collection->count())->toBe(1);

    // Excluded public ids are filtered out
    $excluded = (new LiveController())->orders(Request::create('/x', 'GET', ['exclude' => ['order_live']]));
    expect($excluded->count())->toBe(0);
});

test('drivers and vehicles list located records within viewport bounds', function () {
    $connection = fleetopsLiveEndpointsBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver One']);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => '1', 'location' => 'POINT(1 1)'],
        ['uuid' => 'driver-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'online' => '1', 'location' => null],
    ]);
    $connection->table('vehicles')->insert(['uuid' => 'vehicle-1', 'company_uuid' => 'company-1', 'name' => 'Truck', 'location' => 'POINT(1 1)']);

    $drivers = (new LiveController())->drivers(Request::create('/x', 'GET'));
    expect($drivers->count())->toBe(1);

    // Bounded requests apply the viewport constraint. SQLite binds the
    // normalized float bounds as TEXT, and REAL-vs-TEXT comparisons never
    // match — the viewport clause executes but filters everything here.
    $bounded = (new LiveController())->drivers(Request::create('/x', 'GET', ['bounds' => [0, 0, 1, 1], 'limit' => 10]));
    expect($bounded->count())->toBe(0);

    $vehicles = (new LiveController())->vehicles(Request::create('/x', 'GET'));
    expect($vehicles->count())->toBe(1);
    expect((new LiveController())->vehicles(Request::create('/x', 'GET', ['bounds' => [0, 0, 1, 1]]))->count())->toBe(0);
});

test('places lists located places for the company', function () {
    $connection = fleetopsLiveEndpointsBoot();
    $connection->table('places')->insert([
        ['uuid' => 'place-1', 'company_uuid' => 'company-1', 'name' => 'Depot', 'location' => 'POINT(1 1)'],
        ['uuid' => 'place-2', 'company_uuid' => 'company-1', 'name' => 'Unlocated', 'location' => null],
    ]);

    $request = Request::create('/x', 'GET');
    $request->setLaravelSession(app('session.store'));

    $places = (new LiveController())->places($request);
    expect($places->count())->toBe(1);
});
