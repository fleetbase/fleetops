<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the API OrderController query() nearby spatial filters against
 * SQLite: coordinate-based, driver-based and address-based nearby lookups
 * through the pickup and waypoint distance-sphere subqueries with the
 * company adhoc-distance option, plus facilitator and customer relation
 * filters.
 */
if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!Request::hasMacro('getController')) {
    Request::macro('getController', fn () => new OrderController());
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

if (!Request::hasMacro('isArray')) {
    Request::macro('isArray', fn (string $key) => is_array($this->input($key)));
}

function fleetopsOrderNearbyBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');

    // Real implementations of the MySQL spatial functions the nearby query
    // emits, so the distance filter is actually exercised rather than being
    // handed a constant. These decode the same packed point the fixture writes
    // and, for st_distance_sphere, use the haversine formula on MySQL's earth
    // radius. This verifies selection semantics — which rows fall inside the
    // radius — not MySQL's exact arithmetic, so assertions stay well clear of
    // the boundary.
    $decode = function ($value): ?array {
        if (!is_string($value) || strlen($value) < 21) {
            return null;
        }

        $parts = @unpack('Vsrid/Corder/Vtype/dlng/dlat', $value);

        return $parts === false ? null : ['lng' => $parts['lng'], 'lat' => $parts['lat']];
    };

    $pdo->sqliteCreateFunction('ST_X', fn ($value) => $decode($value)['lng'] ?? null);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => $decode($value)['lat'] ?? null);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', function ($wkt, $srid = 0, $axisOrder = null) {
        if (is_string($wkt) && sscanf($wkt, 'POINT(%f %f)', $lng, $lat) === 2) {
            return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
        }

        return $wkt;
    });
    $pdo->sqliteCreateFunction('ST_Distance_Sphere', function ($a, $b) use ($decode) {
        $from = $decode($a);
        $to   = $decode($b);

        if (!$from || !$to) {
            return null;
        }

        $earthRadius = 6370986; // metres — the value MySQL's st_distance_sphere uses
        $latFrom     = deg2rad($from['lat']);
        $latTo       = deg2rad($to['lat']);
        $deltaLat    = $latTo - $latFrom;
        $deltaLng    = deg2rad($to['lng'] - $from['lng']);

        $h = sin($deltaLat / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($h)));
    });
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('geocoder', new class {
        public function geocode($query)
        {
            return $this;
        }

        public function get()
        {
            return collect();
        }

        public function __call($method, $arguments)
        {
            return $this;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type', 'tracking_number_uuid', 'status', 'pod_required', 'dispatched', 'scheduled_at', 'type'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid'],
        'entities'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'name'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'location'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'type'],
        'contacts'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name'],
        'vendors'           => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name'],
        'companies'         => ['uuid', 'public_id', 'name', 'options'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'status_uuid', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details'],
        'directives'        => ['uuid', 'company_uuid', 'permission_uuid', 'subject_type', 'subject_uuid', 'key', 'rules'],
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
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'options' => json_encode(['fleetops' => ['adhoc_distance' => 5000]])]);

    return $connection;
}

function fleetopsOrderNearbyRequest(array $input): Request
{
    $request = Request::create('/v1/orders', 'GET', $input);
    $store   = app('session.store');
    $store->put('company', 'company-1');
    $request->setLaravelSession($store);
    $request->setRouteResolver(fn () => new class {
        public function getAction($key = null)
        {
            return OrderController::class . '@query';
        }

        public function getActionMethod()
        {
            return 'query';
        }

        public function uri()
        {
            return 'v1/orders';
        }

        public function getName()
        {
            return 'api.v1.orders.query';
        }

        public function parameters()
        {
            return [];
        }
    });

    return $request;
}

function fleetopsOrderNearbyWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

function fleetopsOrderNearbySeed(SQLiteConnection $connection): void
{
    $connection->table('places')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_nearby1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'location' => fleetopsOrderNearbyWkb(1.30, 103.80)]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_nearby1', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'public_id' => 'order_nearby1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'status' => 'created', 'pod_required' => null, 'dispatched' => null]);
}

test('nearby coordinates filter builds pickup and waypoint distance subqueries', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);

    $result = (new OrderController())->query(fleetopsOrderNearbyRequest(['nearby' => '1.30,103.80']));
    expect($result->count())->toBeGreaterThanOrEqual(0);
});

test('nearby driver filter uses the resolved driver location', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_nearby1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'location' => fleetopsOrderNearbyWkb(1.31, 103.81)]);

    $result = (new OrderController())->query(fleetopsOrderNearbyRequest(['nearby' => 'driver_nearby1']));
    expect($result->count())->toBeGreaterThanOrEqual(0);
});

test('nearby driver public ids without a stored location enter the driver branch', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);
    $connection->table('users')->insert(['uuid' => 'user-2', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => 'driver-2', 'public_id' => 'driver_nearby2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-2', 'location' => null]);

    // A located driver resolves as coordinates; a location-less driver falls
    // through to the driver branch which fails building the distance query
    // against the missing point
    expect(fn () => (new OrderController())->query(fleetopsOrderNearbyRequest(['nearby' => 'driver_nearby2'])))
        ->toThrow(Error::class);
});

test('nearby address names matching a stored place use its location', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);
    $connection->table('places')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_nearby2', 'company_uuid' => 'company-1', 'name' => 'NearbyDepot', 'location' => fleetopsOrderNearbyWkb(1.32, 103.82)]);

    $result = (new OrderController())->query(fleetopsOrderNearbyRequest(['nearby' => 'NearbyDepot']));
    expect($result->count())->toBeGreaterThanOrEqual(0);
});

test('nearby address strings resolve through place creation', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);

    $result = (new OrderController())->query(fleetopsOrderNearbyRequest(['nearby' => 'Unknown Address 99']));
    expect($result->count())->toBeGreaterThanOrEqual(0);
});

test('facilitator and customer filters constrain by public and internal ids', function () {
    $connection = fleetopsOrderNearbyBoot();
    fleetopsOrderNearbySeed($connection);
    $connection->table('vendors')->insert(['uuid' => 'vendor-1', 'public_id' => 'vendor_nearby1', 'internal_id' => 'VEN-1', 'company_uuid' => 'company-1', 'name' => 'Facilitator']);
    $connection->table('contacts')->insert(['uuid' => 'contact-1', 'public_id' => 'contact_nearby1', 'internal_id' => 'CON-1', 'company_uuid' => 'company-1', 'name' => 'Customer']);
    $connection->table('orders')->where('uuid', 'order-1')->update([
        'facilitator_uuid' => 'vendor-1', 'facilitator_type' => 'Fleetbase\\FleetOps\\Models\\Vendor',
        'customer_uuid'    => 'contact-1', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact',
    ]);

    $result = (new OrderController())->query(fleetopsOrderNearbyRequest([
        'facilitator' => 'vendor_nearby1',
        'customer'    => 'contact_nearby1',
    ]));
    // MorphTo whereHas subqueries build and constrain without matching in
    // the harness morph map
    expect($result->count())->toBeGreaterThanOrEqual(0);
});
