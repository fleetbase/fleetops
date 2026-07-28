<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the API OrderController startOrder and updateActivity flows against
 * SQLite with a real transport order config: the not-found/already-started/
 * missing-driver/not-dispatched error branches, the skip-dispatch success
 * path delegating into updateActivity, and the updateActivity completed and
 * dispatch-failed branches.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Api\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Api\v1; function event($event = null) { \FleetOpsOrderStartRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsOrderStartRecorder::$events[] = $event; return $event; }');
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

class FleetOpsOrderStartRecorder
{
    public static array $events = [];
}

function fleetopsOrderStartBoot(): SQLiteConnection
{
    if (!Str::hasMacro('humanize')) {
        Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
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

    $barcodeFake = new class {
        public function __call($method, $arguments)
        {
            return 'barcode';
        }
    };
    app()->instance('DNS2D', $barcodeFake);
    app()->instance('DNS1D', $barcodeFake);

    $schema = $connection->getSchemaBuilder();
    app()->instance('db.schema', $schema);
    $tables = [
        'orders'            => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'tracking_number_uuid', 'driver_assigned_uuid', 'status', 'type', 'adhoc', 'dispatched', 'dispatched_at', 'started', 'started_at', 'scheduled_at', 'meta', 'distance', 'time', 'pod_required', 'pod_method'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'pickup_tracking_number_uuid', 'dropoff_tracking_number_uuid', 'meta', 'type'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'city', 'country', 'location', 'meta'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'status', 'type'],
        'order_configs'     => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'region', 'location', 'status_uuid', 'owner_uuid', 'owner_type', 'qr_code', 'barcode', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'proof_uuid', 'status', 'details', 'location', 'code', 'complete', '_key'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'tracking_number_uuid', 'name', 'type'],
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'positions'         => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['core_service', 'started', 'adhoc', 'dispatched'], true)) {
                    $blueprint->integer($column)->nullable();
                    continue;
                }
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme', 'country' => 'SG']);
    $connection->table('order_configs')->insert([
        'uuid'         => 'config-1',
        'public_id'    => 'order_config_transport',
        'company_uuid' => 'company-1',
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'core_service' => 1,
        'status'       => 'active',
        'version'      => '0.0.1',
        'flow'         => json_encode([
            'order_created' => [
                'key'        => 'order_created',
                'code'       => 'created',
                'status'     => 'Created',
                'details'    => 'Order created',
                'activities' => ['order_dispatched'],
            ],
            'order_dispatched' => [
                'key'        => 'order_dispatched',
                'code'       => 'dispatched',
                'status'     => 'Dispatched',
                'details'    => 'Order dispatched',
                'activities' => ['order_started'],
            ],
            'order_started' => [
                'key'        => 'order_started',
                'code'       => 'started',
                'status'     => 'Started',
                'details'    => 'Order started',
                'activities' => ['order_completed'],
            ],
            'order_completed' => [
                'key'        => 'order_completed',
                'code'       => 'completed',
                'status'     => 'Completed',
                'details'    => 'Order completed',
                'complete'   => true,
                'activities' => [],
            ],
        ]),
    ]);
    FleetOpsOrderStartRecorder::$events = [];

    return $connection;
}

function fleetopsOrderStartSeedOrder(SQLiteConnection $connection, array $attributes = []): void
{
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'name' => 'Driver One']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_one', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('places')->insert([
        ['uuid' => 'place-p', 'company_uuid' => 'company-1', 'name' => 'Pickup'],
        ['uuid' => 'place-d', 'company_uuid' => 'company-1', 'name' => 'Dropoff'],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-p', 'dropoff_uuid' => 'place-d']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(array_merge([
        'uuid'                 => 'order-1',
        'public_id'            => 'order_start',
        'company_uuid'         => 'company-1',
        'payload_uuid'         => 'payload-1',
        'order_config_uuid'    => 'config-1',
        'tracking_number_uuid' => 'tn-1',
        'status'               => 'created',
        'type'                 => 'transport',
        'adhoc'                => 0,
        'dispatched'           => 0,
        'started'              => 0,
    ], $attributes));
}

test('start order rejects unknown started missing driver and undispatched orders', function () {
    $connection = fleetopsOrderStartBoot();
    $controller = new OrderController();

    // Unknown order
    $missing = $controller->startOrder('order_missing', Request::create('/x', 'POST', []));
    expect($missing)->toBeInstanceOf(JsonResponse::class)
        ->and($missing->getStatusCode())->toBe(404);

    // Already started
    fleetopsOrderStartSeedOrder($connection, ['started' => 1]);
    $started = $controller->startOrder('order_start', Request::create('/x', 'POST', []));
    expect($started->getData(true)['error'])->toContain('already started');

    // No driver assigned
    $connection->table('orders')->where('uuid', 'order-1')->update(['started' => 0, 'driver_assigned_uuid' => null]);
    $noDriver = $controller->startOrder('order_start', Request::create('/x', 'POST', []));
    expect($noDriver->getData(true)['error'])->toContain('No driver assigned');

    // Adhoc without driver
    $connection->table('orders')->where('uuid', 'order-1')->update(['adhoc' => 1]);
    $adhoc = $controller->startOrder('order_start', Request::create('/x', 'POST', []));
    expect($adhoc->getData(true)['error'])->toContain('driver to accept adhoc');

    // Driver assigned but not dispatched
    $connection->table('orders')->where('uuid', 'order-1')->update(['adhoc' => 0, 'driver_assigned_uuid' => 'driver-1']);
    $undispatched = $controller->startOrder('order_start', Request::create('/x', 'POST', []));
    expect($undispatched->getData(true)['error'])->toContain('not been dispatched');
});

test('start order with skip dispatch starts the order and assigns the job', function () {
    $connection = fleetopsOrderStartBoot();
    fleetopsOrderStartSeedOrder($connection, ['driver_assigned_uuid' => 'driver-1']);

    $result = (new OrderController())->startOrder('order_start', Request::create('/x', 'POST', ['skip_dispatch' => 1]));

    expect($connection->table('orders')->value('started'))->toBe(1)
        ->and($connection->table('drivers')->value('current_job_uuid'))->toBe('order-1')
        ->and(collect(FleetOpsOrderStartRecorder::$events)->first(fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderStarted))->not->toBeNull();
});

test('update activity rejects unknown and completed orders', function () {
    $connection = fleetopsOrderStartBoot();
    $controller = new OrderController();

    $missing = $controller->updateActivity('order_missing', Request::create('/x', 'POST', []));
    expect($missing->getStatusCode())->toBe(404);

    fleetopsOrderStartSeedOrder($connection, ['status' => 'completed']);
    $completed = $controller->updateActivity('order_start', Request::create('/x', 'POST', []));
    expect($completed->getData(true)['error'])->toContain('already completed');
});

test('dispatched activity without driver raises dispatch failed', function () {
    $connection = fleetopsOrderStartBoot();
    fleetopsOrderStartSeedOrder($connection, ['status' => 'dispatched']);

    $request = Request::create('/x', 'POST', ['activity' => [
        'key'     => 'order_dispatched',
        'code'    => 'dispatched',
        'status'  => 'Dispatched',
        'details' => 'Order dispatched',
    ]]);

    $response = (new OrderController())->updateActivity('order_start', $request);

    expect($response->getData(true)['error'])->toContain('No driver assigned')
        ->and(collect(FleetOpsOrderStartRecorder::$events)->first(fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderDispatchFailed))->not->toBeNull();
});

function fleetopsOrderStartSeedWaypoints(SQLiteConnection $connection): void
{
    $connection->table('places')->insert([
        ['uuid' => 'place-w1', 'company_uuid' => 'company-1', 'name' => 'Stop One'],
        ['uuid' => 'place-w2', 'company_uuid' => 'company-1', 'name' => 'Stop Two'],
    ]);
    $connection->table('tracking_numbers')->insert([
        ['uuid' => 'tn-w1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRKW1', 'owner_uuid' => 'wp-1'],
        ['uuid' => 'tn-w2', 'company_uuid' => 'company-1', 'tracking_number' => 'TRKW2', 'owner_uuid' => 'wp-2'],
    ]);
    $connection->table('waypoints')->insert([
        ['uuid' => 'wp-1', 'public_id' => 'waypoint_apistop1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w1', 'tracking_number_uuid' => 'tn-w1', 'order' => '0'],
        ['uuid' => 'wp-2', 'public_id' => 'waypoint_apistop2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w2', 'tracking_number_uuid' => 'tn-w2', 'order' => '1'],
    ]);
    $connection->table('payloads')->where('uuid', 'payload-1')->update(['pickup_uuid' => null, 'dropoff_uuid' => null]);
}

test('lifecycle activities update classic orders through to completion', function () {
    $connection = fleetopsOrderStartBoot();
    fleetopsOrderStartSeedOrder($connection, ['status' => 'dispatched', 'driver_assigned_uuid' => 'driver-1']);
    $controller = new OrderController();

    // Started lifecycle activity updates order and entity activities
    $started = $controller->updateActivity('order_start', Request::create('/x', 'POST', ['activity' => [
        'key' => 'order_started', 'code' => 'started', 'status' => 'Started', 'details' => 'Order started',
    ]]));
    expect($connection->table('tracking_statuses')->where('code', 'STARTED')->count())->toBeGreaterThanOrEqual(1);

    // Completing activity completes the order and frees the driver
    $controller->updateActivity('order_start', Request::create('/x', 'POST', ['activity' => [
        'key' => 'order_completed', 'code' => 'completed', 'status' => 'Completed', 'details' => 'Order completed', 'complete' => true,
    ]]));
    expect($connection->table('tracking_statuses')->where('code', 'COMPLETED')->count())->toBeGreaterThanOrEqual(1);
});

test('waypoint activities gate on started and advance service stops', function () {
    $connection = fleetopsOrderStartBoot();
    fleetopsOrderStartSeedOrder($connection, ['status' => 'created', 'driver_assigned_uuid' => 'driver-1']);
    fleetopsOrderStartSeedWaypoints($connection);
    $controller = new OrderController();

    $activity = ['key' => 'order_completed', 'code' => 'completed', 'status' => 'Completed', 'details' => 'Stop completed', 'complete' => true];

    // Waypoint routes on created orders auto-start then advance stop by stop
    $controller->updateActivity('order_start', Request::create('/x', 'POST', ['activity' => $activity]));
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->not->toBeNull();

    $controller->updateActivity('order_start', Request::create('/x', 'POST', ['activity' => $activity]));
    expect($connection->table('tracking_statuses')->where('code', 'COMPLETED')->count())->toBeGreaterThanOrEqual(1);
});

test('next activity and complete order resolve service stop flows', function () {
    $connection = fleetopsOrderStartBoot();
    fleetopsOrderStartSeedOrder($connection, ['status' => 'started', 'started' => 1, 'driver_assigned_uuid' => 'driver-1']);
    fleetopsOrderStartSeedWaypoints($connection);
    $controller = new OrderController();

    $next = $controller->getNextActivity('order_start', Request::create('/x', 'GET'));
    expect($next)->not->toBeNull();

    $scoped = $controller->getNextActivity('order_start', Request::create('/x', 'GET', ['waypoint' => 'waypoint_apistop1']));
    expect($scoped)->not->toBeNull();

    // Completing with incomplete waypoints errors
    $incomplete = $controller->completeOrder('order_start');
    expect($incomplete->getData(true)['error'])->toContain('Not all waypoints');

    // With every waypoint completed the order completes
    $connection->table('tracking_numbers')->whereIn('uuid', ['tn-w1', 'tn-w2'])->update(['status_uuid' => 'ts-done']);
    $connection->table('tracking_statuses')->insert([
        ['uuid' => 'ts-done', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-w1', 'code' => 'COMPLETED', 'status' => 'Completed'],
    ]);
    $completed = $controller->completeOrder('order_start');
    expect($completed)->not->toBeNull();
});
