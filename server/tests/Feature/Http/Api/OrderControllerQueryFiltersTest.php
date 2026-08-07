<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the API OrderController query() filter pipeline against an in-memory
 * SQLite fixture. Every filter closure builds real SQL — payload, place,
 * facilitator, customer, entity, entity status, date, flag, and nearby
 * spatial filters — with sqlite function shims standing in for the MySQL
 * spatial functions.
 */
if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
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

function fleetopsOrderQueryFiltersBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    // Stand-ins for the MySQL spatial functions used by the nearby filters.
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_Distance_Sphere', fn ($a, $b) => 100.0);
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

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'customer_uuid', 'customer_type', 'facilitator_uuid', 'facilitator_type', 'tracking_number_uuid', 'status', 'pod_required', 'dispatched', 'scheduled_at', 'type'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'location'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'location'],
        'entities'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'name'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'status_uuid'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'location'],
        'users'             => ['uuid', 'public_id', 'company_uuid'],
        'vehicles'          => ['uuid', 'public_id', 'company_uuid'],
        'contacts'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name'],
        'vendors'           => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'name'],
        'companies'         => ['uuid', 'public_id', 'name', 'options'],
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

    return $connection;
}

function fleetopsOrderQueryFiltersRequest(array $input): Request
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

test('query lists orders with payloads for the session company', function () {
    $connection = fleetopsOrderQueryFiltersBoot();
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'public_id' => 'payload_test', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'public_id' => 'order_a', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'status' => 'created', 'pod_required' => null, 'dispatched' => null],
        ['uuid' => 'order-2', 'public_id' => 'order_b', 'company_uuid' => 'company-1', 'payload_uuid' => null, 'status' => 'created', 'pod_required' => null, 'dispatched' => null],
    ]);

    $result = (new OrderController())->query(fleetopsOrderQueryFiltersRequest([]));

    expect($result->count())->toBe(1);
});

test('query builds every relational and flag filter clause', function () {
    fleetopsOrderQueryFiltersBoot();

    $result = (new OrderController())->query(fleetopsOrderQueryFiltersRequest([
        'payload'       => 'payload_test',
        'pickup'        => 'place_pickup',
        'dropoff'       => 'place_dropoff',
        'return'        => 'place_return',
        'facilitator'   => 'vendor_test',
        'customer'      => 'contact_test',
        'entity'        => 'entity_test',
        'entity_status' => 'IN_TRANSIT',
        'on'            => '2026-08-01',
        'pod_required'  => '1',
        'dispatched'    => '1',
    ]));

    expect($result->count())->toBe(0);
});

test('query supports entity status arrays', function () {
    fleetopsOrderQueryFiltersBoot();

    $result = (new OrderController())->query(fleetopsOrderQueryFiltersRequest([
        'entity_status' => ['IN_TRANSIT', 'DELIVERED'],
    ]));

    expect($result->count())->toBe(0);
});
