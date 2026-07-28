<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Models\session')) {
    eval('namespace Fleetbase\Models; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } public function missing($k) { return \session($k) === null; } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderManager;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Covers the tracking intelligence service and context builder against
 * SQLite: tracked results and eta summaries produced through a stubbed
 * provider manager with cache passthrough, cache key composition, context
 * building with driver origins, missing-driver and stale-location
 * warnings, payload fallback origins, waypoint stop statuses, and stop
 * construction from waypoints.
 */
class FleetOpsTrackingStubManager extends TrackingProviderManager
{
    public function __construct()
    {
        parent::__construct(new TrackingProviderRegistry());
    }

    public function track(TrackingContext $context, TrackingOptions $options): TrackingProviderResult
    {
        return new TrackingProviderResult(
            provider: 'stub',
            distanceMeters: 5000.0,
            durationSeconds: 600.0,
            legs: [
                ['distance_meters' => 2500.0, 'duration_seconds' => 300.0],
                ['distance_meters' => 2500.0, 'duration_seconds' => 300.0],
            ],
            confidence: 'high'
        );
    }
}

function fleetopsTrackingBoot(): SQLiteConnection
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

    app()->instance(TrackingProviderRegistry::class, new TrackingProviderRegistry());

    Cache::swap(new class {
        public function tags($tags)
        {
            return $this;
        }

        public function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public function get($key, $default = null)
        {
            return is_callable($default) ? $default() : $default;
        }

        public function put($key, $value, $ttl = null)
        {
            return true;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'driver_assigned_uuid', 'tracking_number_uuid', 'status', 'type', 'meta', 'started', 'started_at', 'dispatched', 'scheduled_at', 'distance', 'time'],
        'payloads'          => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'            => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', 'meta', 'type'],
        'waypoints'         => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type', 'status_code'],
        'entities'          => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'tracking_numbers'  => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'status_uuid', '_key'],
        'tracking_statuses' => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'location', 'online', 'status'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'companies'         => ['uuid', 'public_id', 'name', 'country'],
        'settings'          => ['key', 'value'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['online', 'started', 'dispatched'], true)) {
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

    return $connection;
}

function fleetopsTrackingWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

function fleetopsTrackingSeedOrder(SQLiteConnection $connection, bool $withDriver = true): Order
{
    $connection->table('places')->insertOrIgnore([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_track1', 'company_uuid' => 'company-1', 'name' => 'Pickup', 'location' => fleetopsTrackingWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_track2', 'company_uuid' => 'company-1', 'name' => 'Dropoff', 'location' => fleetopsTrackingWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insertOrIgnore(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111', 'dropoff_uuid' => '22222222-2222-4222-8222-222222222222']);
    if ($withDriver) {
        $connection->table('users')->insertOrIgnore(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
        $connection->table('drivers')->insertOrIgnore(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'driver_track1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'location' => fleetopsTrackingWkb(1.25, 103.75), 'updated_at' => now()->subHour()]);
    }
    $connection->table('orders')->insertOrIgnore([
        'uuid'                 => 'order-1', 'public_id' => 'order_track1', 'company_uuid' => 'company-1',
        'payload_uuid'         => 'payload-1', 'status' => 'created', 'started' => 0,
        'driver_assigned_uuid' => $withDriver ? '44444444-4444-4444-8444-444444444444' : null,
    ]);

    return Order::query()->where('uuid', 'order-1')->first();
}

test('track and eta produce provider results through the cache', function () {
    $connection = fleetopsTrackingBoot();
    $order      = fleetopsTrackingSeedOrder($connection);

    $service = new TrackingIntelligenceService(new TrackingContextBuilder(), new FleetOpsTrackingStubManager());
    $tracked = $service->track($order, []);

    expect($tracked['provider'])->toBe('stub')
        ->and($tracked['confidence'])->toBe('high')
        ->and($tracked['progress'] ?? null)->toBeArray()
        ->and($tracked)->toBeArray();

    $eta = $service->eta($order, []);
    expect($eta['provider'])->toBe('stub')
        ->and($eta['confidence'])->toBe('high')
        ->and($eta['lifecycle'])->toBeArray()
        ->and($eta['warnings'])->toBeArray();
});

test('context builder resolves driver origins stops and warnings', function () {
    $connection = fleetopsTrackingBoot();
    $order      = fleetopsTrackingSeedOrder($connection);

    $context = (new TrackingContextBuilder())->build($order, TrackingOptions::fromArray([]));
    expect($context->driverLocation?->getLat())->toBe(1.25)
        ->and($context->origin?->getLat())->toBe(1.25)
        ->and($context->stops)->toHaveCount(2)
        ->and($context->remainingStops)->toHaveCount(2)
        ->and($context->activeStop)->not->toBeNull()
        ->and($context->warnings)->toContain('stale_driver_location');
});

test('context builder falls back to payload origin without a driver', function () {
    $connection = fleetopsTrackingBoot();
    $order      = fleetopsTrackingSeedOrder($connection, false);

    $context = (new TrackingContextBuilder())->build($order, TrackingOptions::fromArray([]));
    expect($context->driverLocation)->toBeNull()
        ->and($context->warnings)->toContain('missing_driver_location')
        ->and($context->origin?->getLat())->toBe(1.30);
});

test('waypoint statuses and stop construction resolve tracking codes', function () {
    $connection = fleetopsTrackingBoot();
    fleetopsTrackingSeedOrder($connection);
    $connection->table('tracking_numbers')->insert([
        ['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK1', 'status_uuid' => 'ts-1'],
        ['uuid' => 'tn-2', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK2', 'status_uuid' => 'ts-2'],
    ]);
    $connection->table('tracking_statuses')->insert([
        ['uuid' => 'ts-1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'COMPLETED', 'status' => 'Completed'],
        ['uuid' => 'ts-2', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-2', 'code' => 'IN_PROGRESS', 'status' => 'In Progress'],
    ]);
    $connection->table('waypoints')->insert([
        ['uuid' => 'wp-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '11111111-1111-4111-8111-111111111111', 'tracking_number_uuid' => 'tn-1', 'order' => 0, 'status_code' => null],
        ['uuid' => 'wp-2', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '22222222-2222-4222-8222-222222222222', 'tracking_number_uuid' => null, 'order' => 1, 'status_code' => null],
        ['uuid' => 'wp-3', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1', 'place_uuid' => '22222222-2222-4222-8222-222222222222', 'tracking_number_uuid' => 'tn-2', 'order' => 2, 'status_code' => null],
    ]);

    $builder = new TrackingContextBuilder();

    $withTracking = Waypoint::query()->where('uuid', 'wp-1')->first();
    $plain        = Waypoint::query()->where('uuid', 'wp-2')->first();
    $inProgress   = Waypoint::query()->where('uuid', 'wp-3')->first();

    $status = new ReflectionMethod(TrackingContextBuilder::class, 'waypointServiceStopStatus');
    $status->setAccessible(true);
    expect($status->invoke($builder, $withTracking))->toBe('completed')
        ->and($status->invoke($builder, $inProgress))->toBe('in_progress')
        // Waypoints without tracking numbers fall back to their own status
        // code accessor, which is empty here
        ->and($status->invoke($builder, $plain))->toBeNull();

    $fromWaypoint = new ReflectionMethod(TrackingContextBuilder::class, 'stopFromWaypoint');
    $fromWaypoint->setAccessible(true);
    $stop = $fromWaypoint->invoke($builder, $inProgress, 3);
    expect($stop->type)->toBe('waypoint')
        ->and($stop->sequence)->toBe(3)
        ->and($stop->completed)->toBeFalse();

    $completedWaypoint = Waypoint::query()->where('uuid', 'wp-1')->first();
    $completedStop     = $fromWaypoint->invoke($builder, $completedWaypoint, 4);
    expect($completedStop->completed)->toBeTrue();
});
