<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the internal OrderController start/updateActivity/nextActivity/
 * setDestination flows against SQLite with the transport order-config flow:
 * start guards and success with driver job assignment, the
 * proof-of-delivery requirement, dispatch-without-driver failure, lifecycle
 * activity updates, next-activity resolution, and destination selection.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function event($event = null) { \FleetOpsInternalActivityRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsInternalActivityRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { \FleetOpsInternalActivityRecorder::$dispatched[] = $job; return new \Fleetbase\TestSupport\PendingDispatch(); }');
}

class FleetOpsInternalActivityRecorder
{
    public static array $events     = [];
    public static array $dispatched = [];
}

function fleetopsInternalActivityBoot(): SQLiteConnection
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
        'proofs'            => ['uuid', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'remarks', 'raw_data', 'data'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'positions'         => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'destination_uuid', 'coordinates', 'heading', 'bearing', 'speed', 'altitude', 'order_uuid', '_key'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['core_service', 'started', 'adhoc', 'dispatched', 'pod_required'], true)) {
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
    FleetOpsInternalActivityRecorder::$events     = [];
    FleetOpsInternalActivityRecorder::$dispatched = [];

    return $connection;
}

function fleetopsInternalActivitySeedOrder(SQLiteConnection $connection, array $attributes = []): void
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
        'public_id'            => 'order_internal',
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

test('start guards missing started and driverless orders then starts', function () {
    $connection = fleetopsInternalActivityBoot();
    $controller = new OrderController();

    // Unknown order
    $missing = $controller->start(Request::create('/x', 'POST', ['order' => 'order-missing']));
    expect($missing->getData(true)['error'])->toContain('Unable to find order');

    // Already started
    fleetopsInternalActivitySeedOrder($connection, ['started' => 1]);
    $started = $controller->start(Request::create('/x', 'POST', ['order' => 'order-1']));
    expect($started->getData(true)['error'])->toContain('already been started');

    // No driver
    $connection->table('orders')->where('uuid', 'order-1')->update(['started' => 0]);
    $noDriver = $controller->start(Request::create('/x', 'POST', ['order' => 'order-1']));
    expect($noDriver->getData(true)['error'])->toContain('No driver assigned');

    // Success starts the order and assigns the driver job
    $connection->table('orders')->where('uuid', 'order-1')->update(['driver_assigned_uuid' => 'driver-1']);
    $controller->start(Request::create('/x', 'POST', ['order' => 'order-1']));
    expect($connection->table('orders')->value('started'))->toBe(1)
        ->and($connection->table('drivers')->value('current_job_uuid'))->toBe('order-1')
        ->and(collect(FleetOpsInternalActivityRecorder::$events)->first(fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderStarted))->not->toBeNull();
});

test('update activity enforces proof and dispatch preconditions', function () {
    $connection = fleetopsInternalActivityBoot();
    $controller = new OrderController();

    $missing = $controller->updateActivity('order-missing', Request::create('/x', 'POST', []));
    expect($missing->getData(true)['error'])->toContain('No order found');

    fleetopsInternalActivitySeedOrder($connection, ['pod_required' => 1]);

    // Completing activity without proof is rejected
    $activity = ['key' => 'order_completed', 'code' => 'completed', 'status' => 'Completed', 'details' => 'Order completed', 'complete' => true];
    $noProof  = $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $activity]));
    expect($noProof->getStatusCode())->toBe(422);

    // Dispatched activity without an assigned driver fails dispatch
    $dispatched = ['key' => 'order_dispatched', 'code' => 'dispatched', 'status' => 'Dispatched', 'details' => 'Order dispatched'];
    $failed     = $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $dispatched]));
    expect($failed->getData(true)['error'])->toContain('No driver assigned')
        ->and(collect(FleetOpsInternalActivityRecorder::$events)->first(fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderDispatchFailed))->not->toBeNull();

    // Lifecycle activity updates the order normally with proof bypassed
    $startedActivity = ['key' => 'order_started', 'code' => 'started', 'status' => 'Started', 'details' => 'Order started'];
    $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $startedActivity, 'bypass_proof' => 1]));
    expect($connection->table('tracking_statuses')->where('code', 'STARTED')->count())->toBeGreaterThanOrEqual(1);
});

test('next activity resolves the flow and destination selection persists', function () {
    $connection = fleetopsInternalActivityBoot();
    $controller = new OrderController();

    fleetopsInternalActivitySeedOrder($connection);
    $activities = $controller->nextActivity('order_internal', Request::create('/x', 'GET'));
    expect(collect($activities->getData(true))->pluck('code')->all())->toContain('dispatched');

    // Pickup/dropoff-only orders cannot change destination
    $single = $controller->setDestination('order_internal', 'place-d');
    expect($single->getStatusCode())->toBe(422)
        ->and($single->getData(true)['error'])->toContain('multi-waypoint');

    // Multi-waypoint orders validate and persist the destination
    $connection->table('places')->insert(['uuid' => 'place-w', 'company_uuid' => 'company-1', 'name' => 'Waypoint']);
    $connection->table('waypoints')->insert(['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w', 'order' => '1']);

    $invalid = $controller->setDestination('order_internal', 'place-unknown');
    expect($invalid->getStatusCode())->toBe(422);

    $controller->setDestination('order_internal', 'place-d');
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('place-d');
});

function fleetopsInternalActivitySeedWaypoints(SQLiteConnection $connection): void
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
        ['uuid' => 'wp-1', 'public_id' => 'waypoint_stopone', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w1', 'tracking_number_uuid' => 'tn-w1', 'order' => '0'],
        ['uuid' => 'wp-2', 'public_id' => 'waypoint_stoptwo', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-w2', 'tracking_number_uuid' => 'tn-w2', 'order' => '1'],
    ]);
}

test('waypoint service stop activities gate progress and advance to completion', function () {
    $connection = fleetopsInternalActivityBoot();
    $controller = new OrderController();

    fleetopsInternalActivitySeedOrder($connection, ['driver_assigned_uuid' => 'driver-1']);
    fleetopsInternalActivitySeedWaypoints($connection);
    // Waypoint-only route so two completions exhaust the stops
    $connection->table('payloads')->where('uuid', 'payload-1')->update(['pickup_uuid' => null, 'dropoff_uuid' => null]);

    // Waypoint activity requires a started order first
    $activity = ['key' => 'order_completed', 'code' => 'completed', 'status' => 'Completed', 'details' => 'Stop completed', 'complete' => true];
    $gated    = $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $activity, 'bypass_proof' => 1]));
    expect($gated->getStatusCode())->toBe(422);

    // Started orders complete the current stop then advance to the next
    $connection->table('orders')->where('uuid', 'order-1')->update(['started' => 1, 'status' => 'started']);
    $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $activity, 'bypass_proof' => 1]));
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->not->toBeNull();

    // Completing the final stop completes the order itself
    $controller->updateActivity('order_internal', Request::create('/x', 'POST', ['activity' => $activity, 'bypass_proof' => 1]));
    expect($connection->table('tracking_statuses')->where('code', 'COMPLETED')->count())->toBeGreaterThanOrEqual(1);
});

test('next activity resolves waypoint stops for started orders', function () {
    $connection = fleetopsInternalActivityBoot();
    $controller = new OrderController();

    fleetopsInternalActivitySeedOrder($connection, ['driver_assigned_uuid' => 'driver-1', 'started' => 1, 'status' => 'started']);
    fleetopsInternalActivitySeedWaypoints($connection);

    $current = $controller->nextActivity('order_internal', Request::create('/x', 'GET'));
    expect($current)->not->toBeNull();

    $scoped = $controller->nextActivity('order_internal', Request::create('/x', 'GET', ['waypoint' => 'waypoint_stopone']));
    expect($scoped)->not->toBeNull();
});
