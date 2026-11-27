<?php

use Fleetbase\FleetOps\Events\ManifestUpdated;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\ManifestController as DriverManifestController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\ManifestController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver;
use Fleetbase\FleetOps\Jobs\PublishScheduledManifest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\ManifestPublished;
use Fleetbase\FleetOps\Orchestration\Engines\GreedyOrchestrationEngine;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Support\Dispatch\ManifestPublisher;
use Fleetbase\FleetOps\Support\Dispatch\RouteReassignment;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Dispatch workspace's server flow against SQLite: plan tomorrow's orders,
 * commit the plan dated for tomorrow, publish it to drivers (now or later),
 * move an order between drivers through every entry point with both routes
 * kept in step, and insert a late order into a route that is under way.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\Dispatch\event')) {
    eval('namespace Fleetbase\FleetOps\Support\Dispatch; function event($event = null) { if (\DispatchWorkspaceRecorder::$failEvents) { throw new \RuntimeException(\'socket down\'); } \DispatchWorkspaceRecorder::$events[] = $event; return $event; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \DispatchWorkspaceRecorder::$modelEvents[] = $event; return $event; }');
}

class DispatchWorkspaceRecorder
{
    public static array $events      = [];
    public static array $modelEvents = [];
    public static array $notified    = [];
    public static bool $failEvents   = false;
    public static bool $failNotify   = false;

    public static function reset(): void
    {
        static::$events               = [];
        static::$modelEvents          = [];
        static::$notified             = [];
        static::$failEvents           = false;
        static::$failNotify           = false;
        DispatchRecorder::$dispatched = [];
    }

    /** `[change, manifest public id, orders]` for each manifest.updated broadcast. */
    public static function manifestChanges(): array
    {
        return array_values(array_map(
            fn (ManifestUpdated $event) => [$event->data['change'], $event->data['id'], $event->data['orders']],
            array_filter(static::$events, fn ($event) => $event instanceof ManifestUpdated)
        ));
    }
}

/** A point as MySQL stores it (SRID, then WKB), which the spatial models read back. */
function dispatchWorkspaceWkb(float $latitude, float $longitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('e', $longitude) . pack('e', $latitude);
}

function dispatchWorkspaceBoot(): SQLiteConnection
{
    DispatchWorkspaceRecorder::reset();
    Carbon::setTestNow(Carbon::parse('2026-11-27 16:00:00'));

    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo        = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite']);
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    if (!EloquentModel::getEventDispatcher()) {
        EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    }
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
    app()->instance('db.schema', $connection->getSchemaBuilder());
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class {
        public function send($notifiables, $notification)
        {
            if (DispatchWorkspaceRecorder::$failNotify) {
                throw new RuntimeException('push failed');
            }
            DispatchWorkspaceRecorder::$notified[] = [$notifiables->uuid, $notification];
        }

        public function sendNow($notifiables, $notification, ?array $channels = null)
        {
            $this->send($notifiables, $notification);
        }
    });
    app()->instance('log', new class {
        public array $warnings = [];

        public function warning($message, array $context = [])
        {
            $this->warnings[] = $message;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'companies'          => ['uuid', 'public_id', 'name', 'options'],
        'users'              => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'email', 'phone'],
        'drivers'            => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid', 'meta'],
        'vehicles'           => ['uuid', 'public_id', 'company_uuid', 'type', 'plate_number', 'seating_capacity', 'vendor_uuid', 'photo_uuid', 'avatar_url', 'name', 'internal_id', 'location', 'online', 'speed', 'heading', 'altitude', 'year', 'make', 'model', 'class', 'color', 'call_sign', 'status', 'specs', 'vin_data', 'telematics', 'meta', 'slug', 'vin', 'trim', 'plate_number_type'],
        'orders'             => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'tracking_number_uuid', 'order_config_uuid', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'manifest_uuid', 'status', 'type', 'scheduled_at', 'dispatched', 'started', 'adhoc', 'is_route_optimized', 'meta', 'required_skills', 'orchestrator_priority', 'time_window_start', 'time_window_end', 'updated_by_uuid'],
        'payloads'           => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'meta', 'capacity_weight_kg', 'capacity_volume_m3', 'capacity_parcels'],
        'places'             => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', 'meta', 'owner_uuid', 'owner_type'],
        'waypoints'          => ['uuid', 'public_id', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'order', 'type'],
        'entities'           => ['uuid', 'payload_uuid', 'name'],
        'tracking_numbers'   => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'status_uuid'],
        'tracking_statuses'  => ['uuid', 'company_uuid', 'tracking_number_uuid', 'code', 'status'],
        'manifests'          => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'driver_uuid', 'vehicle_uuid', 'status', 'scheduled_date', 'published_at', 'publish_at', 'started_at', 'completed_at', 'total_distance_m', 'total_duration_s', 'stop_count', 'notes', 'meta'],
        'manifest_stops'     => ['uuid', 'public_id', 'manifest_uuid', 'order_uuid', 'place_uuid', 'waypoint_uuid', 'status', 'sequence', 'estimated_arrival', 'actual_arrival', 'distance_from_prev_m', 'duration_from_prev_s', 'meta'],
        'orchestration_runs' => ['uuid', 'public_id', 'company_uuid', 'created_by_uuid', 'mode', 'engine', 'scheduled_date', 'order_ids', 'assignments', 'unassigned', 'unassigned_reasons', 'summary', 'committed_at'],
        'schedule_items'     => ['uuid', 'public_id', 'company_uuid', 'assignee_uuid', 'assignee_type', 'start_at', 'end_at', 'status'],
        'fleets'             => ['uuid', 'public_id', 'company_uuid', 'name'],
        'vendors'            => ['uuid', 'public_id', 'company_uuid', 'name'],
        'files'              => ['uuid', 'public_id', 'company_uuid', 'type', 'path', 'disk', 'bucket', 'original_filename', 'content_type', 'file_size', 'meta', 'subject_uuid', 'subject_type'],
        'fleet_vehicles'     => ['uuid', 'fleet_uuid', 'vehicle_uuid'],
        'fleet_drivers'      => ['uuid', 'fleet_uuid', 'driver_uuid'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->{in_array($column, ['location'], true) ? 'binary' : 'string'}($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1', 'user' => 'user-dispatcher']);
    $connection->table('companies')->insert(['uuid' => 'company-1', 'name' => 'Acme']);

    // Two drivers with their vans, both near the depot.
    foreach (['a' => [1.300, 103.800], 'b' => [1.400, 103.900]] as $key => [$lat, $lng]) {
        $connection->table('users')->insert(['uuid' => "user-{$key}", 'company_uuid' => 'company-1', 'name' => 'Driver ' . strtoupper($key)]);
        $connection->table('vehicles')->insert(['uuid' => "vehicle-{$key}", 'public_id' => "vehicle_{$key}", 'company_uuid' => 'company-1', 'name' => 'Van ' . strtoupper($key), 'location' => dispatchWorkspaceWkb($lat, $lng)]);
        $connection->table('drivers')->insert(['uuid' => "driver-{$key}", 'public_id' => "driver_{$key}", 'company_uuid' => 'company-1', 'user_uuid' => "user-{$key}", 'vehicle_uuid' => "vehicle-{$key}", 'location' => dispatchWorkspaceWkb($lat, $lng)]);
    }

    return $connection;
}

/**
 * An order with a drop-off at the given point, scheduled for a day.
 */
function dispatchWorkspaceOrder(SQLiteConnection $connection, string $key, array $point, ?string $scheduledAt, array $attributes = []): void
{
    $connection->table('places')->insert(['uuid' => "place-{$key}", 'public_id' => "place_{$key}", 'company_uuid' => 'company-1', 'name' => "Stop {$key}", 'street1' => "{$key} Road", 'location' => dispatchWorkspaceWkb($point[0], $point[1])]);
    $connection->table('payloads')->insert(['uuid' => "payload-{$key}", 'public_id' => "payload_{$key}", 'company_uuid' => 'company-1', 'dropoff_uuid' => "place-{$key}"]);
    $connection->table('orders')->insert(array_merge([
        'uuid'         => "order-{$key}",
        'public_id'    => "order_{$key}",
        'company_uuid' => 'company-1',
        'payload_uuid' => "payload-{$key}",
        'status'       => 'created',
        'scheduled_at' => $scheduledAt,
    ], $attributes));
}

/**
 * A route for a driver on 28 November with stops for the given orders, in
 * order. `$done` lists orders whose stop is already completed.
 */
function dispatchWorkspaceManifest(SQLiteConnection $connection, string $key, string $driver, array $orders, string $status = 'published', array $done = []): void
{
    $connection->table('manifests')->insert([
        'uuid'           => "manifest-{$key}",
        'public_id'      => "manifest_{$key}",
        'company_uuid'   => 'company-1',
        'driver_uuid'    => "driver-{$driver}",
        'vehicle_uuid'   => "vehicle-{$driver}",
        'status'         => $status,
        'scheduled_date' => '2026-11-28',
        'published_at'   => $status === 'draft' ? null : '2026-11-27 12:00:00',
        'stop_count'     => count($orders),
    ]);
    foreach (array_values($orders) as $index => $order) {
        $connection->table('manifest_stops')->insert([
            'uuid'          => "stop-{$order}",
            'public_id'     => "stop_{$order}",
            'manifest_uuid' => "manifest-{$key}",
            'order_uuid'    => "order-{$order}",
            'place_uuid'    => "place-{$order}",
            'status'        => in_array($order, $done, true) ? 'completed' : 'pending',
            'sequence'      => $index + 1,
        ]);
        $connection->table('orders')->where('uuid', "order-{$order}")->update(['manifest_uuid' => "manifest-{$key}", 'driver_assigned_uuid' => "driver-{$driver}", 'vehicle_assigned_uuid' => "vehicle-{$driver}"]);
    }
}

/** `order key => sequence` of a route's live stops. */
function dispatchWorkspaceStops(SQLiteConnection $connection, string $manifest): array
{
    return $connection->table('manifest_stops')
        ->where('manifest_uuid', Illuminate\Support\Str::isUuid($manifest) ? $manifest : "manifest-{$manifest}")
        ->whereNull('deleted_at')
        ->orderBy('sequence')
        ->get()
        ->mapWithKeys(fn ($stop) => [str_replace('order-', '', $stop->order_uuid) => (int) $stop->sequence])
        ->all();
}

function dispatchWorkspaceOrchestrator(): OrchestrationController
{
    $registry = new OrchestrationEngineRegistry();
    $registry->register(new GreedyOrchestrationEngine());

    return new class($registry) extends OrchestrationController {
        protected function orchestratorEngineSetting(): string
        {
            return 'greedy';
        }
    };
}

afterEach(function () {
    Carbon::setTestNow();
    session(['user' => null]);
});

test('a dispatcher plans tomorrow, commits it dated tomorrow and drivers see it only once published', function () {
    $connection = dispatchWorkspaceBoot();
    dispatchWorkspaceOrder($connection, '1', [1.310, 103.810], '2026-11-28 09:00:00');
    dispatchWorkspaceOrder($connection, '2', [1.320, 103.820], '2026-11-28 10:30:00');
    // Planned for another day, and a canceled order: neither is in tomorrow's run.
    dispatchWorkspaceOrder($connection, '3', [1.330, 103.830], '2026-11-29 09:00:00');
    dispatchWorkspaceOrder($connection, '4', [1.340, 103.840], '2026-11-28 09:00:00', ['status' => 'canceled']);

    $controller = dispatchWorkspaceOrchestrator();

    // Without a scope nothing is planned.
    $unscoped = $controller->run(Request::create('/run', 'POST', ['mode' => 'allocate']));
    expect($unscoped->getStatusCode())->toBe(422)
        ->and($unscoped->getData(true)['error'])->toContain('order_ids or a scheduled_date');

    $run = $controller->run(Request::create('/run', 'POST', [
        'mode'           => 'allocate',
        'scheduled_date' => '2026-11-28',
        'vehicle_ids'    => ['vehicle_a'],
        'options'        => ['engine' => 'greedy'],
    ]));
    $plan = $run->getData(true);

    // One van, greedy single-order allocation: the nearer order is placed and
    // the other is listed with the reason it was not.
    $reason = ['reason' => 'no_available_vehicle', 'message' => 'No vehicle was available for this order.'];
    expect($run->getStatusCode())->toBe(200)
        ->and(array_column($plan['assignments'], 'order_id'))->toBe(['order_1'])
        ->and($plan['unassigned'])->toBe(['order_2'])
        ->and($plan['unassigned_reasons'])->toBe(['order_2' => $reason])
        ->and($plan['scheduled_date'])->toBe('2026-11-28')
        ->and($plan['plan_run_id'])->toStartWith('plan_');

    $stored = $connection->table('orchestration_runs')->first();
    expect($stored->public_id)->toBe($plan['plan_run_id'])
        ->and($stored->scheduled_date)->toStartWith('2026-11-28')
        ->and($stored->mode)->toBe('allocate')
        ->and($stored->engine)->toBe('greedy')
        ->and(json_decode($stored->order_ids, true))->toEqualCanonicalizing(['order_1', 'order_2'])
        ->and(json_decode($stored->unassigned_reasons, true))->toBe(['order_2' => $reason])
        ->and($stored->created_by_uuid)->toBe('user-dispatcher')
        ->and($stored->committed_at)->toBeNull();

    // order_statuses narrows the scope; an unplannable status is refused.
    $dispatchedOnly = $controller->run(Request::create('/run', 'POST', ['mode' => 'allocate', 'scheduled_date' => '2026-11-28', 'order_statuses' => ['dispatched']]));
    $bogusStatus    = $controller->run(Request::create('/run', 'POST', ['mode' => 'allocate', 'scheduled_date' => '2026-11-28', 'order_statuses' => ['canceled']]));
    $badDate        = $controller->run(Request::create('/run', 'POST', ['mode' => 'allocate', 'scheduled_date' => '2026-11-31']));
    expect($dispatchedOnly->getData(true)['message'])->toBe('No orders found for the given criteria.')
        ->and($bogusStatus->getStatusCode())->toBe(422)
        ->and($badDate->getStatusCode())->toBe(422);

    $commit = $controller->commit(Request::create('/commit', 'POST', [
        'assignments'    => $plan['assignments'],
        'scheduled_date' => '2026-11-28',
        'plan_run_id'    => $plan['plan_run_id'],
    ]));
    $committed = $commit->getData(true);

    $manifest = $connection->table('manifests')->first();
    expect($commit->getStatusCode())->toBe(200)
        ->and($committed['scheduled_date'])->toBe('2026-11-28')
        ->and($committed['published'])->toBe([])
        ->and($manifest->scheduled_date)->toStartWith('2026-11-28')
        ->and($manifest->status)->toBe('draft')
        ->and($manifest->driver_uuid)->toBe('driver-a')
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('scheduled_at'))->toStartWith('2026-11-28 09:00')
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('manifest_uuid'))->toBe($manifest->uuid)
        ->and($connection->table('orders')->where('uuid', 'order-2')->value('manifest_uuid'))->toBeNull()
        ->and($connection->table('orchestration_runs')->value('committed_at'))->not->toBeNull()
        // A draft tells nobody.
        ->and(DispatchWorkspaceRecorder::$modelEvents)->toBe([]);

    // The driver app does not see the draft.
    $driverApi = new DriverManifestController();
    expect($driverApi->forDriver(new Request(), 'driver_a')->collection)->toHaveCount(0)
        ->and($driverApi->show($manifest->public_id)->getStatusCode())->toBe(404);

    // Publishing releases it, once.
    $publish = (new ManifestController())->publish(new Request(), $manifest->public_id);
    $again   = (new ManifestController())->publish(new Request(), $manifest->public_id);

    expect($publish->getStatusCode())->toBe(200)
        ->and($publish->getData(true)['manifest']['status'])->toBe('published')
        ->and($again->getStatusCode())->toBe(422)
        ->and($driverApi->forDriver(new Request(), 'driver_a')->collection)->toHaveCount(1)
        ->and(DispatchWorkspaceRecorder::$notified)->toHaveCount(1)
        ->and(DispatchWorkspaceRecorder::$notified[0][0])->toBe('driver-a')
        ->and(DispatchWorkspaceRecorder::$notified[0][1])->toBeInstanceOf(ManifestPublished::class)
        ->and(DispatchWorkspaceRecorder::manifestChanges())->toBe([['published', $manifest->public_id, ['order_1']]])
        ->and(collect(DispatchRecorder::$dispatched)->where('job', NotifyBulkAssignedDriver::class)->first()['arguments'][1])->toBe('driver-a');

    // The driver starting the route makes it active.
    ManifestStop::where('manifest_uuid', $manifest->uuid)->orderBy('sequence')->first()->markArrived();
    expect($connection->table('manifests')->value('status'))->toBe('active');
});

test('a commit can publish now or book a publish, and a re-plan takes orders off their old route', function () {
    $connection = dispatchWorkspaceBoot();
    dispatchWorkspaceOrder($connection, '1', [1.310, 103.810], null);
    dispatchWorkspaceOrder($connection, '2', [1.320, 103.820], null);
    dispatchWorkspaceManifest($connection, 'old', 'b', ['1', '2']);

    $controller = dispatchWorkspaceOrchestrator();
    $now        = $controller->commit(Request::create('/commit', 'POST', [
        'assignments'    => [['order_id' => 'order_1', 'vehicle_id' => 'vehicle_a', 'driver_id' => 'driver_a', 'sequence' => 1, 'arrival' => Carbon::parse('2026-11-28 08:15:00')->timestamp]],
        'scheduled_date' => '2026-11-28',
        'publish'        => true,
    ]))->getData(true);

    $new = Manifest::where('public_id', $now['manifests'][0])->first();
    expect($now['published'])->toBe([$new->public_id])
        ->and($new->status)->toBe('published')
        // An order with no time takes its planned arrival on the plan's day.
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('scheduled_at'))->toStartWith('2026-11-28 08:15')
        // The old route closed up behind it and its driver was told.
        ->and(dispatchWorkspaceStops($connection, 'old'))->toBe(['2' => 1])
        ->and($connection->table('manifests')->where('uuid', 'manifest-old')->value('stop_count'))->toBe('1')
        ->and(dispatchWorkspaceStops($connection, $new->uuid))->toBe(['1' => 1])
        ->and(DispatchWorkspaceRecorder::manifestChanges()[0])->toBe(['stop_removed', 'manifest_old', ['order_1']]);

    $later = $controller->commit(Request::create('/commit', 'POST', [
        'assignments'    => [['order_id' => 'order_2', 'vehicle_id' => 'vehicle_a', 'driver_id' => 'driver_a', 'sequence' => 1]],
        'scheduled_date' => '2026-11-28',
        'publish_at'     => '2026-11-28T05:00:00+00:00',
    ]))->getData(true);

    $booked = Manifest::where('public_id', $later['manifests'][0])->first();
    expect($later['scheduled'])->toBe([$booked->public_id])
        ->and($booked->status)->toBe('draft')
        ->and($booked->publish_at->toIso8601String())->toBe('2026-11-28T05:00:00+00:00')
        ->and($connection->table('orders')->where('uuid', 'order-2')->value('scheduled_at'))->toStartWith('2026-11-28 00:00')
        ->and(collect(DispatchRecorder::$dispatched)->where('job', PublishScheduledManifest::class)->first()['arguments'])->toBe([$booked->uuid, '2026-11-28T05:00:00+00:00']);

    $badDate = $controller->commit(Request::create('/commit', 'POST', ['assignments' => [['order_id' => 'order_2']], 'scheduled_date' => 'tomorrow']));
    $badTime = $controller->commit(Request::create('/commit', 'POST', ['assignments' => [['order_id' => 'order_2']], 'publish_at' => 'not a time']));
    expect($badDate->getStatusCode())->toBe(422)
        ->and($badTime->getStatusCode())->toBe(422);
});

test('a booked publish runs at its time and a stale booking does nothing', function () {
    $connection = dispatchWorkspaceBoot();
    dispatchWorkspaceOrder($connection, '1', [1.310, 103.810], '2026-11-28 09:00:00');
    dispatchWorkspaceManifest($connection, 'm', 'a', ['1'], 'draft');

    $controller = new ManifestController();
    $booked     = $controller->publish(new Request(['publish_at' => '2026-11-28 05:00:00']), 'manifest_m');
    $job        = new PublishScheduledManifest('manifest-m', Manifest::where('uuid', 'manifest-m')->first()->publish_at->toIso8601String());

    expect($booked->getStatusCode())->toBe(200)
        ->and($booked->getData(true)['manifest']['status'])->toBe('draft');

    // Too early: nothing happens.
    $job->handle(app(ManifestPublisher::class));
    expect($connection->table('manifests')->value('status'))->toBe('draft');

    // Re-booked for later: the first job is stale.
    Carbon::setTestNow(Carbon::parse('2026-11-28 05:30:00'));
    $controller->publish(new Request(['publish_at' => '2026-11-28 06:00:00']), 'manifest_m');
    $job->handle(app(ManifestPublisher::class));
    expect($connection->table('manifests')->value('status'))->toBe('draft');

    // The current booking, once due, publishes.
    Carbon::setTestNow(Carbon::parse('2026-11-28 06:00:01'));
    (new PublishScheduledManifest('manifest-m', Manifest::where('uuid', 'manifest-m')->first()->publish_at->toIso8601String()))->handle(app(ManifestPublisher::class));
    (new PublishScheduledManifest('manifest-gone', '2026-11-28T06:00:00+00:00'))->handle(app(ManifestPublisher::class));
    expect($connection->table('manifests')->value('status'))->toBe('published')
        ->and($connection->table('manifests')->value('publish_at'))->toBeNull();

    $unreadable = $controller->publish(new Request(['publish_at' => 'soon']), 'manifest_m');
    $notDraft   = $controller->publish(new Request(['publish_at' => '2026-11-29 06:00:00']), 'manifest_m');
    expect($unreadable->getStatusCode())->toBe(422)
        ->and($notDraft->getStatusCode())->toBe(422)
        ->and($notDraft->getData(true)['error'])->toContain('published');
});

test('publishing a day publishes its drafts and reports what it skipped', function () {
    $connection = dispatchWorkspaceBoot();
    dispatchWorkspaceOrder($connection, '1', [1.310, 103.810], '2026-11-28 09:00:00');
    dispatchWorkspaceOrder($connection, '2', [1.320, 103.820], '2026-11-28 09:00:00');
    dispatchWorkspaceOrder($connection, '3', [1.330, 103.830], '2026-11-28 09:00:00');
    dispatchWorkspaceManifest($connection, 'a', 'a', ['1'], 'draft');
    dispatchWorkspaceManifest($connection, 'b', 'b', ['2'], 'draft');
    dispatchWorkspaceManifest($connection, 'c', 'b', ['3'], 'active');
    // A driverless draft publishes without telling anyone.
    $connection->table('manifests')->where('uuid', 'manifest-b')->update(['driver_uuid' => null]);

    $controller = new ManifestController();
    $day        = $controller->publishMany(new Request(['scheduled_date' => '2026-11-28']))->getData(true);
    $named      = $controller->publishMany(new Request(['ids' => ['manifest_c'], 'publish_at' => '2026-11-27 15:00:00']))->getData(true);

    expect($day)->toBe(['published' => ['manifest_a', 'manifest_b'], 'scheduled' => [], 'skipped' => []])
        ->and($named)->toBe(['published' => [], 'scheduled' => [], 'skipped' => [['id' => 'manifest_c', 'status' => 'active']]])
        ->and(DispatchWorkspaceRecorder::$notified)->toHaveCount(1);

    dispatchWorkspaceOrder($connection, '4', [1.340, 103.840], '2026-11-29 09:00:00');
    $connection->table('manifests')->insert(['uuid' => 'manifest-d', 'public_id' => 'manifest_d', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-a', 'status' => 'draft', 'scheduled_date' => '2026-11-29']);
    $connection->table('manifests')->insert(['uuid' => 'manifest-f', 'public_id' => 'manifest_f', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-b', 'status' => 'draft', 'scheduled_date' => '2026-11-29']);
    $booked = $controller->publishMany(new Request(['ids' => ['manifest_d'], 'publish_at' => '2026-11-29 05:00:00']))->getData(true);
    // A booking for a time already past publishes at once.
    $past = $controller->publishMany(new Request(['ids' => ['manifest_f'], 'publish_at' => '2026-11-27 15:00:00']))->getData(true);
    expect($booked['scheduled'])->toBe(['manifest_d'])
        ->and($past['published'])->toBe(['manifest_f']);

    // A push or broadcast that fails does not undo the publish.
    $connection->table('manifests')->insert(['uuid' => 'manifest-e', 'public_id' => 'manifest_e', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-a', 'status' => 'draft', 'scheduled_date' => '2026-11-30']);
    DispatchWorkspaceRecorder::$failNotify = true;
    DispatchWorkspaceRecorder::$failEvents = true;
    expect($controller->publishMany(new Request(['ids' => ['manifest_e']]))->getData(true)['published'])->toBe(['manifest_e']);

    expect($controller->publishMany(new Request())->getStatusCode())->toBe(422)
        ->and($controller->publishMany(new Request(['scheduled_date' => '28/11/2026']))->getStatusCode())->toBe(422)
        ->and($controller->publishMany(new Request(['ids' => ['manifest_a'], 'publish_at' => 'later']))->getStatusCode())->toBe(422);
});

test('reassigning an order moves its stop between routes from every entry point', function () {
    $connection = dispatchWorkspaceBoot();
    foreach (['1', '2', '3', '4', '5'] as $key) {
        dispatchWorkspaceOrder($connection, $key, [1.3 + $key / 100, 103.8 + $key / 100], '2026-11-28 09:00:00');
    }
    dispatchWorkspaceManifest($connection, 'a', 'a', ['1', '2', '3']);
    dispatchWorkspaceManifest($connection, 'b', 'b', ['4']);

    // Bulk assign: order 2 goes to driver B's route for the same day.
    $orders = new OrderController();
    $bulk   = $orders->bulkAssignDriver(Request::create('/x', 'PATCH', ['ids' => ['order-2'], 'driver' => 'driver-b']));

    // Order 1 is already driver A's: assigning it again changes nothing.
    $orders->bulkAssignDriver(Request::create('/x', 'PATCH', ['ids' => ['order-1'], 'driver' => 'driver-a', 'silent' => true]));

    expect($bulk->getData(true)['count'])->toBe(1)
        ->and(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1, '3' => 2])
        ->and(dispatchWorkspaceStops($connection, 'b'))->toBe(['4' => 1, '2' => 2])
        ->and($connection->table('orders')->where('uuid', 'order-2')->first())->toMatchArray([
            'driver_assigned_uuid'  => 'driver-b',
            'vehicle_assigned_uuid' => 'vehicle-b',
            'manifest_uuid'         => 'manifest-b',
        ])
        ->and($connection->table('manifests')->where('uuid', 'manifest-a')->value('stop_count'))->toBe('2')
        ->and($connection->table('manifests')->where('uuid', 'manifest-b')->value('stop_count'))->toBe('2')
        ->and(DispatchWorkspaceRecorder::manifestChanges())->toBe([
            ['stop_removed', 'manifest_a', ['order_2']],
            ['stop_added', 'manifest_b', ['order_2']],
        ]);

    // Scheduler drag: order 3 to driver B.
    DispatchWorkspaceRecorder::$events = [];
    $orders->scheduleOrder(Request::create('/x', 'PATCH', ['order' => 'order-3', 'driver_id' => 'driver_b', 'scheduled_at' => '2026-11-28 13:00:00']));
    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1])
        ->and(dispatchWorkspaceStops($connection, 'b'))->toBe(['4' => 1, '2' => 2, '3' => 3]);

    // Dragged to a day the driver has no route: the order leaves its route.
    $orders->scheduleOrder(Request::create('/x', 'PATCH', ['order' => 'order-3', 'scheduled_at' => '2026-11-30 13:00:00']));
    expect(dispatchWorkspaceStops($connection, 'b'))->toBe(['4' => 1, '2' => 2])
        ->and($connection->table('orders')->where('uuid', 'order-3')->value('manifest_uuid'))->toBeNull()
        ->and($connection->table('manifest_stops')->where('uuid', 'stop-3')->value('deleted_at'))->not->toBeNull();

    // The order form's PUT: the driver was saved, the route follows.
    $order                       = Order::where('uuid', 'order-4')->first();
    $order->driver_assigned_uuid = 'driver-a';
    $order->save();
    $orders->onAfterUpdate(new Request(), $order);
    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1, '4' => 2])
        ->and(dispatchWorkspaceStops($connection, 'b'))->toBe(['2' => 1])
        ->and($connection->table('orders')->where('uuid', 'order-4')->value('vehicle_assigned_uuid'))->toBe('vehicle-a');

    // Unassigned from the form: the order leaves every route.
    $order->driver_assigned_uuid = null;
    $order->save();
    $orders->onAfterUpdate(new Request(), $order);
    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1])
        ->and($connection->table('orders')->where('uuid', 'order-4')->value('manifest_uuid'))->toBeNull();

    // An unrouted order assigned to a driver with a route that day joins it at the end.
    $service = app(RouteReassignment::class);
    $service->reassign(Order::where('uuid', 'order-5')->first(), Driver::where('uuid', 'driver-a')->first());
    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1, '5' => 2]);

    // Moving within the same route with a position re-sequences it.
    DispatchWorkspaceRecorder::$events = [];
    $service->reassign(Order::where('uuid', 'order-5')->first(), Driver::where('uuid', 'driver-a')->first(), ['position' => 1]);
    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['5' => 1, '1' => 2])
        ->and(DispatchWorkspaceRecorder::manifestChanges())->toBe([['resequenced', 'manifest_a', ['order_5']]])
        ->and($service->followDriverChange(Order::where('uuid', 'order-5')->first()))->toBeNull();
});

test('a finished stop stays where it was driven and drafts move without telling drivers', function () {
    $connection = dispatchWorkspaceBoot();
    foreach (['1', '2', '3'] as $key) {
        dispatchWorkspaceOrder($connection, $key, [1.3 + $key / 100, 103.8 + $key / 100], '2026-11-28 09:00:00');
    }
    // Two drafts and a published route for driver B on the same day.
    dispatchWorkspaceManifest($connection, 'a', 'a', ['1', '2'], 'draft');
    dispatchWorkspaceManifest($connection, 'live', 'b', ['3'], 'published');
    $connection->table('manifests')->insert(['uuid' => 'manifest-b', 'public_id' => 'manifest_b', 'company_uuid' => 'company-1', 'driver_uuid' => 'driver-b', 'vehicle_uuid' => 'vehicle-b', 'status' => 'draft', 'scheduled_date' => '2026-11-28', 'stop_count' => 0, 'created_at' => '2026-11-27 15:00:00']);
    $connection->table('manifests')->where('uuid', 'manifest-live')->update(['created_at' => '2026-11-27 10:00:00']);

    $service = app(RouteReassignment::class);
    // Coming off a draft, the order goes to driver B's draft, not the live route.
    $service->reassign(Order::where('uuid', 'order-2')->first(), Driver::where('uuid', 'driver-b')->first());

    expect(dispatchWorkspaceStops($connection, 'b'))->toBe(['2' => 1])
        ->and(dispatchWorkspaceStops($connection, 'live'))->toBe(['3' => 1])
        ->and(DispatchWorkspaceRecorder::manifestChanges())->toBe([]);

    // Order 1's stop is done: it stays on route A; a new stop is made on B.
    $connection->table('manifest_stops')->where('uuid', 'stop-1')->update(['status' => 'completed']);
    $service->reassign(Order::where('uuid', 'order-1')->first(), Driver::where('uuid', 'driver-b')->first(), ['manifest' => Manifest::where('uuid', 'manifest-live')->first(), 'position' => 1]);

    expect(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1])
        ->and(dispatchWorkspaceStops($connection, 'live'))->toBe(['1' => 1, '3' => 2])
        ->and($connection->table('manifest_stops')->where('manifest_uuid', 'manifest-live')->where('order_uuid', 'order-1')->value('place_uuid'))->toBe('place-1');

    // Released by a re-plan from a route it is not on: nothing to do.
    expect($service->release(Order::where('uuid', 'order-1')->first(), Manifest::where('uuid', 'manifest-live')->first()))->toBeNull()
        ->and($service->release(Order::where('uuid', 'order-3')->first()))->not->toBeNull()
        ->and(dispatchWorkspaceStops($connection, 'live'))->toBe(['1' => 1]);

    // A broadcast that fails is logged; the move stands.
    DispatchWorkspaceRecorder::$failEvents = true;
    $service->reassign(Order::where('uuid', 'order-1')->first(), Driver::where('uuid', 'driver-a')->first(), ['manifest' => null]);
    expect(dispatchWorkspaceStops($connection, 'live'))->toBe([])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('driver_assigned_uuid'))->toBe('driver-a')
        ->and(app('log')->warnings)->toContain('Unable to broadcast manifest.updated');
});

test('a late order is inserted into an active route at its cheapest position', function () {
    $connection = dispatchWorkspaceBoot();
    // Driver A drives east along a line; stop 1 is already done.
    dispatchWorkspaceOrder($connection, '1', [1.300, 103.810], '2026-11-28 09:00:00');
    dispatchWorkspaceOrder($connection, '2', [1.300, 103.830], '2026-11-28 09:00:00');
    dispatchWorkspaceOrder($connection, '3', [1.300, 103.850], '2026-11-28 09:00:00');
    dispatchWorkspaceManifest($connection, 'a', 'a', ['1', '2', '3'], 'active', ['1']);
    // Driver B is far away.
    dispatchWorkspaceOrder($connection, '4', [1.450, 103.950], '2026-11-28 09:00:00');
    dispatchWorkspaceManifest($connection, 'b', 'b', ['4'], 'published');
    // The late order sits between stops 2 and 3.
    dispatchWorkspaceOrder($connection, 'late', [1.300, 103.840], '2026-11-28 12:00:00');

    $controller = new ManifestController();
    $fit        = $controller->bestFit(new Request(['order' => 'order_late']))->getData(true);

    expect($fit['scheduled_date'])->toBe('2026-11-28')
        ->and(array_column($fit['candidates'], 'driver_id'))->toBe(['driver_a', 'driver_b'])
        ->and($fit['candidates'][0])->toMatchArray(['manifest_id' => 'manifest_a', 'position' => 3, 'status' => 'active', 'vehicle_id' => 'vehicle_a', 'driver_name' => 'Driver A', 'stop_count' => 3])
        ->and($fit['candidates'][0]['extra_distance_m'])->toBeLessThan($fit['candidates'][1]['extra_distance_m'])
        ->and($fit['candidates'][0]['extra_km'])->toEqual(round($fit['candidates'][0]['extra_distance_m'] / 1000, 2));

    // Without a position the order goes to its best fit.
    $inserted = $controller->insertStop(new Request(['order' => 'order_late']), 'manifest_a')->getData(true);
    expect($inserted['position'])->toBe(3)
        ->and(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1, '2' => 2, 'late' => 3, '3' => 4])
        ->and($connection->table('orders')->where('uuid', 'order-late')->value('driver_assigned_uuid'))->toBe('driver-a')
        ->and(DispatchWorkspaceRecorder::manifestChanges())->toBe([['stop_added', 'manifest_a', ['order_late']]]);

    // A position before the finished stop lands just after it.
    $moved = $controller->insertStop(new Request(['order' => 'order-4', 'position' => 1]), 'manifest_a')->getData(true);
    expect($moved['position'])->toBe(2)
        ->and(dispatchWorkspaceStops($connection, 'a'))->toBe(['1' => 1, '4' => 2, '2' => 3, 'late' => 4, '3' => 5])
        ->and(dispatchWorkspaceStops($connection, 'b'))->toBe([]);

    $connection->table('orders')->where('uuid', 'order-1')->update(['status' => 'completed']);
    $connection->table('manifests')->insert(['uuid' => 'manifest-done', 'public_id' => 'manifest_done', 'company_uuid' => 'company-1', 'status' => 'completed', 'scheduled_date' => '2026-11-28']);
    dispatchWorkspaceOrder($connection, 'nowhere', [0.0, 0.0], null);

    expect($controller->insertStop(new Request(['order' => 'order_late']), 'manifest_done')->getStatusCode())->toBe(422)
        ->and($controller->insertStop(new Request(['order' => 'order_missing']), 'manifest_a')->getStatusCode())->toBe(422)
        ->and($controller->insertStop(new Request(), 'manifest_a')->getStatusCode())->toBe(422)
        ->and($controller->insertStop(new Request(['order' => 'order_1']), 'manifest_a')->getStatusCode())->toBe(422)
        ->and($controller->insertStop(new Request(['order' => 'order_late', 'position' => 0]), 'manifest_a')->getStatusCode())->toBe(422)
        ->and($controller->bestFit(new Request(['order' => 'order_missing']))->getStatusCode())->toBe(422)
        ->and($controller->bestFit(new Request(['order' => 'order_late', 'scheduled_date' => 'Friday']))->getStatusCode())->toBe(422)
        ->and($controller->bestFit(new Request(['order' => 'order_nowhere']))->getStatusCode())->toBe(422)
        ->and($controller->bestFit(new Request(['order' => 'order_late', 'scheduled_date' => '2026-12-01']))->getData(true)['candidates'])->toBe([]);

    // An order with no coordinates is appended when no position is given.
    $appended = $controller->insertStop(new Request(['order' => 'order_nowhere']), 'manifest_a')->getData(true);
    expect($appended['position'])->toBe(6);
});
