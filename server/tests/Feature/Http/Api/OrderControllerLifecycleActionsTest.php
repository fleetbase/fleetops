<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Covers the API OrderController lifecycle actions against SQLite with a
 * real transport order-config flow: next-activity resolution with
 * proof-of-delivery flags, order completion with the incomplete-waypoint
 * guard, cancellation, and current-destination selection for multi-stop
 * payloads.
 */
if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsOrderLifecycleRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { \FleetOpsOrderLifecycleRecorder::$dispatched[] = $job; return new \Fleetbase\TestSupport\PendingDispatch(); }');
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

class FleetOpsOrderLifecycleRecorder
{
    public static array $events     = [];
    public static array $dispatched = [];
}

function fleetopsOrderLifecycleBoot(): SQLiteConnection
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
    FleetOpsOrderLifecycleRecorder::$events = [];

    return $connection;
}

function fleetopsOrderLifecycleSeedOrder(SQLiteConnection $connection, array $attributes = []): void
{
    $connection->table('places')->insert([
        ['uuid' => 'place-p', 'company_uuid' => 'company-1', 'name' => 'Pickup'],
        ['uuid' => 'place-d', 'company_uuid' => 'company-1', 'name' => 'Dropoff'],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-p', 'dropoff_uuid' => 'place-d']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(array_merge([
        'uuid'                 => 'order-1',
        'public_id'            => 'order_lifecycle',
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

test('next activity resolves flow steps and missing orders 404', function () {
    $connection = fleetopsOrderLifecycleBoot();
    $controller = new OrderController();

    $missing = $controller->getNextActivity('order_missing', Request::create('/x', 'GET'));
    expect($missing)->toBeInstanceOf(JsonResponse::class)
        ->and($missing->getStatusCode())->toBe(404);

    fleetopsOrderLifecycleSeedOrder($connection);
    $activities = $controller->getNextActivity('order_lifecycle', Request::create('/x', 'GET'));
    $decoded    = $activities->getData(true);
    expect(collect($decoded)->pluck('code')->all())->toContain('dispatched');
});

test('next activity flags proof of delivery on completing activities', function () {
    $connection = fleetopsOrderLifecycleBoot();
    fleetopsOrderLifecycleSeedOrder($connection, ['status' => 'started', 'started' => 1, 'pod_required' => 1, 'pod_method' => 'signature']);

    // Move the flow to started so the next activity completes the order
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'STARTED', 'status' => 'Started']);
    $connection->table('tracking_numbers')->where('uuid', 'tn-1')->update(['status_uuid' => 'ts-1']);

    $activities = (new OrderController())->getNextActivity('order_lifecycle', Request::create('/x', 'GET'));
    $decoded    = collect($activities->getData(true));
    $completing = $decoded->first(fn ($activity) => ($activity['code'] ?? null) === 'completed');

    expect($completing)->not->toBeNull()
        ->and($completing['require_pod'] ?? null)->toBeTrue()
        ->and($completing['pod_method'] ?? null)->toBe('signature');
});

test('complete order guards incomplete waypoints and cancel cancels', function () {
    $connection = fleetopsOrderLifecycleBoot();
    $controller = new OrderController();

    $missing = $controller->completeOrder('order_missing');
    expect($missing->getStatusCode())->toBe(404);

    // Incomplete waypoint markers block completion
    fleetopsOrderLifecycleSeedOrder($connection, ['status' => 'started', 'started' => 1]);
    $connection->table('waypoints')->insert(['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => 'place-p', 'tracking_number_uuid' => 'tn-wp', 'order' => '1']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-wp', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK-WP', 'status_uuid' => 'ts-wp']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-wp', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-wp', 'code' => 'CREATED', 'status' => 'Created']);

    $blocked = $controller->completeOrder('order_lifecycle');
    expect($blocked->getData(true)['error'])->toContain('Not all waypoints completed');

    // Cancellation runs the cancel transition
    $canceled = $controller->cancelOrder('order_lifecycle');
    expect($connection->table('orders')->value('status'))->toBe('canceled');

    expect($controller->cancelOrder('order_missing')->getStatusCode())->toBe(404);
});

test('set destination validates and persists the current service stop', function () {
    $connection = fleetopsOrderLifecycleBoot();
    $controller = new OrderController();

    expect($controller->setDestination('order_missing', 'place-d')->getStatusCode())->toBe(404);

    fleetopsOrderLifecycleSeedOrder($connection);

    // Unknown place keys are rejected
    $invalid = $controller->setDestination('order_lifecycle', 'place-unknown');
    expect($invalid->getStatusCode())->toBe(422);

    // Valid destination persists onto the payload
    $controller->setDestination('order_lifecycle', 'place-d');
    expect($connection->table('payloads')->value('current_waypoint_uuid'))->toBe('place-d');
});
