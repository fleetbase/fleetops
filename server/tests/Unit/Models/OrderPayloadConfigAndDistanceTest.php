<?php

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Models; function dispatch($job = null) { $GLOBALS["fleetopsOrderModelDispatches"][] = $job; return new class { public function afterCommit() { return $this; } public function __call($m, $a) { return $this; } }; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\config')) {
    eval('namespace Fleetbase\FleetOps\Support; function config($key = null, $default = null) { return $key === "fleetops.distance_matrix.provider" ? "calculate" : $default; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Support\OrderTracker;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Covers Order model payload creation and insertion with embedded place
 * resolution, quote purchasing, preliminary and accurate distance setters,
 * order-config resolution by uuid and company default, driver loading,
 * notifiable resolution, dispatch-state helpers, and time-window
 * normalization against SQLite.
 */
function fleetopsOrderPayloadBoot(): SQLiteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_X', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_Y', fn ($value) => 0.5);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axisOrder = null) => $wkt);
    $pdo->sqliteCreateFunction('st_distance_sphere', fn ($a, $b) => 100.0);
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

    Cache::swap(new class {
        public function tags($tags)
        {
            return $this;
        }

        public function flush()
        {
            return true;
        }

        public function remember($key, $ttl, $callback)
        {
            return $callback();
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'              => ['uuid', 'public_id', 'internal_id', 'company_uuid', 'payload_uuid', 'order_config_uuid', 'customer_uuid', 'customer_type', 'purchase_rate_uuid', 'transaction_uuid', 'driver_assigned_uuid', 'tracking_number_uuid', 'status', 'type', 'meta', 'distance', 'time', 'dispatched', 'dispatched_at', 'scheduled_at', 'started', 'adhoc', 'pod_required'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'return_uuid', 'current_waypoint_uuid', 'type', 'meta', '_key'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'name', 'street1', 'location', 'type', '_import_id'],
        'waypoints'           => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'place_uuid', 'tracking_number_uuid', 'customer_uuid', 'customer_type', 'order', 'type'],
        'entities'            => ['uuid', 'public_id', 'company_uuid', 'payload_uuid', 'destination_uuid', 'name', 'type'],
        'tracking_numbers'    => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'owner_type', 'status_uuid', 'region', '_key'],
        'tracking_statuses'   => ['uuid', 'public_id', 'company_uuid', 'tracking_number_uuid', 'code', 'status', 'details'],
        'purchase_rates'      => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'payload_uuid', 'transaction_uuid', 'status', 'meta', '_key'],
        'transactions'        => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'gateway_transaction_id', 'gateway', 'amount', 'currency', 'type', 'status', 'meta', '_key'],
        'order_configs'       => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', '_key'],
        'companies'           => ['uuid', 'public_id', 'name', 'country', 'currency'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'location', 'online', 'status'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status'],
        'custom_fields'       => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'name', 'label', 'type', 'options', 'meta', 'required', 'order'],
        'custom_field_values' => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                if (in_array($column, ['dispatched', 'started', 'adhoc', 'pod_required', 'online', 'core_service'], true)) {
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

    return $connection;
}

function fleetopsOrderPayloadWkb(float $lat, float $lng): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('d', $lng) . pack('d', $lat);
}

function fleetopsOrderPayloadFetch(SQLiteConnection $connection, string $uuid): Order
{
    $connection->table('orders')->insertOrIgnore(['uuid' => $uuid, 'company_uuid' => 'company-1']);

    return Order::query()->where('uuid', $uuid)->first();
}

test('time window values inherit the order reference date', function () {
    fleetopsOrderPayloadBoot();

    $order = new Order();
    $order->setRawAttributes(['scheduled_at' => '2026-07-01 08:00:00'], true);

    $normalise = new ReflectionMethod(Order::class, 'normaliseTimeWindowValue');
    $normalise->setAccessible(true);

    expect($normalise->invoke($order, '1970-01-01 09:30:00'))->toBe('2026-07-01 09:30:00')
        ->and($normalise->invoke($order, null))->toBeNull()
        ->and($normalise->invoke($order, '2026-07-04 10:00:00'))->toBe('2026-07-04 10:00:00');

    $created = new Order();
    $created->setRawAttributes(['created_at' => '2026-06-15 00:00:00'], true);
    expect($normalise->invoke($created, '1970-01-01 07:15:00'))->toBe('2026-06-15 07:15:00');
});

test('create insert and get payload resolve embedded places', function () {
    $connection = fleetopsOrderPayloadBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_ordpay1', 'company_uuid' => 'company-1', 'location' => fleetopsOrderPayloadWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_ordpay2', 'company_uuid' => 'company-1', 'location' => fleetopsOrderPayloadWkb(1.35, 103.85)],
        ['uuid' => '33333333-3333-4333-8333-333333333333', 'public_id' => 'place_ordpay3', 'company_uuid' => 'company-1', 'location' => fleetopsOrderPayloadWkb(1.40, 103.90)],
    ]);

    $order       = fleetopsOrderPayloadFetch($connection, 'order-1');
    $order->type = 'transport';

    $payload = $order->createPayload([
        'pickup'  => ['uuid' => '11111111-1111-4111-8111-111111111111'],
        'dropoff' => ['uuid' => '22222222-2222-4222-8222-222222222222'],
        'return'  => ['uuid' => '33333333-3333-4333-8333-333333333333'],
    ]);

    expect($payload)->toBeInstanceOf(Payload::class)
        ->and($payload->pickup_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($payload->dropoff_uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($payload->return_uuid)->toBe('33333333-3333-4333-8333-333333333333')
        ->and($payload->type)->toBe('transport');

    $seen = null;
    $order->getPayload(function ($loaded) use (&$seen) {
        $seen = $loaded;
    });
    expect($seen)->toBeInstanceOf(Payload::class);

    $inserted = $order->insertPayload([
        'pickup'  => ['uuid' => '11111111-1111-4111-8111-111111111111'],
        'dropoff' => ['uuid' => '22222222-2222-4222-8222-222222222222'],
        'return'  => ['uuid' => '33333333-3333-4333-8333-333333333333'],
    ]);

    expect($inserted)->toBeInstanceOf(Payload::class)
        ->and($inserted->pickup_uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($connection->table('payloads')->count())->toBeGreaterThanOrEqual(2);
});

test('purchase quote creates and attaches a purchase rate', function () {
    $connection = fleetopsOrderPayloadBoot();
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'customer_uuid' => 'contact-1', 'customer_type' => 'contact', 'payload_uuid' => 'payload-1']);

    $order  = Order::query()->where('uuid', 'order-1')->first();
    $result = $order->purchaseQuote('quote-1', ['channel' => 'test']);

    $rate = $connection->table('purchase_rates')->first();
    expect($result)->toBeTrue()
        ->and($rate->service_quote_uuid)->toBe('quote-1')
        ->and($rate->payload_uuid)->toBe('payload-1')
        ->and($connection->table('orders')->value('purchase_rate_uuid'))->toBe($rate->uuid);
});

test('distance and time setters use origin and destination positions', function () {
    $connection = fleetopsOrderPayloadBoot();
    $connection->table('places')->insert([
        ['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'place_orddist1', 'company_uuid' => 'company-1', 'location' => fleetopsOrderPayloadWkb(1.30, 103.80)],
        ['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'place_orddist2', 'company_uuid' => 'company-1', 'location' => fleetopsOrderPayloadWkb(1.35, 103.85)],
    ]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => '11111111-1111-4111-8111-111111111111', 'dropoff_uuid' => '22222222-2222-4222-8222-222222222222']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'payload_uuid' => 'payload-1']);

    $order = Order::query()->where('uuid', 'order-1')->first();
    $order->setPreliminaryDistanceAndTime();
    expect((float) $connection->table('orders')->value('distance'))->toBeGreaterThan(0);

    $connection->table('orders')->where('uuid', 'order-1')->update(['distance' => null, 'time' => null]);
    $order = Order::query()->where('uuid', 'order-1')->first();
    $order->setDistanceAndTime(['provider' => 'calculate']);
    expect((float) $connection->table('orders')->value('distance'))->toBeGreaterThan(0);

    // Orders without payloads bail out of both setters
    $connection->table('orders')->insert(['uuid' => 'order-2', 'company_uuid' => 'company-1']);
    $bare = Order::query()->where('uuid', 'order-2')->first();
    expect($bare->setPreliminaryDistanceAndTime())->toBe($bare)
        ->and($bare->setDistanceAndTime(['provider' => 'calculate']))->toBe($bare);

    // A driver assignment overrides the origin position
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'driver_orddist1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'location' => fleetopsOrderPayloadWkb(1.20, 103.70)]);
    $connection->table('orders')->where('uuid', 'order-1')->update(['driver_assigned_uuid' => '44444444-4444-4444-8444-444444444444']);
    $order  = Order::query()->where('uuid', 'order-1')->first();
    $origin = $order->getCurrentOriginPosition();
    expect($origin?->getLat())->toBe(1.20);
});

test('config resolution prefers uuid then company default', function () {
    $connection = fleetopsOrderPayloadBoot();
    $flow       = json_encode([
        'order_created' => ['key' => 'order_created', 'code' => 'created', 'status' => 'Created', 'details' => 'Order created', 'activities' => []],
    ]);
    $connection->table('order_configs')->insert([
        ['uuid' => '55555555-5555-4555-8555-555555555555', 'public_id' => 'order_config_custom', 'company_uuid' => 'company-1', 'name' => 'Custom', 'key' => 'custom', 'namespace' => 'company:order-config:custom', 'core_service' => 1, 'status' => 'active', 'version' => '0.0.1', 'flow' => $flow],
        ['uuid' => '66666666-6666-4666-8666-666666666666', 'public_id' => 'order_config_transport', 'company_uuid' => 'company-1', 'name' => 'Transport', 'key' => 'transport', 'namespace' => 'system:order-config:transport', 'core_service' => 1, 'status' => 'active', 'version' => '0.0.1', 'flow' => $flow],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-1', 'company_uuid' => 'company-1', 'order_config_uuid' => '55555555-5555-4555-8555-555555555555', 'type' => 'custom'],
        ['uuid' => 'order-2', 'company_uuid' => 'company-1', 'order_config_uuid' => null, 'type' => 'default'],
    ]);

    $byUuid = Order::query()->where('uuid', 'order-1')->first();
    expect($byUuid->config()?->uuid)->toBe('55555555-5555-4555-8555-555555555555');

    $byCompany = Order::query()->where('uuid', 'order-2')->first();
    expect($byCompany->config()?->uuid)->toBe('66666666-6666-4666-8666-666666666666');

    $ensured = $byCompany->ensureOrderConfig();
    expect($ensured?->uuid)->toBe('66666666-6666-4666-8666-666666666666')
        ->and($connection->table('orders')->where('uuid', 'order-2')->value('type'))->toBe('transport')
        ->and($connection->table('orders')->where('uuid', 'order-2')->value('order_config_uuid'))->toBe('66666666-6666-4666-8666-666666666666');
});

test('driver loading notifiable resolution and dispatch helpers', function () {
    $connection = fleetopsOrderPayloadBoot();
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1', 'type' => 'user']);
    $connection->table('drivers')->insert(['uuid' => '44444444-4444-4444-8444-444444444444', 'public_id' => 'driver_ordhelp1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'location' => fleetopsOrderPayloadWkb(1.22, 103.72)]);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'company_uuid' => 'company-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'DISPATCHED', 'status' => 'Dispatched']);
    $connection->table('orders')->insert(['uuid' => 'order-1', 'company_uuid' => 'company-1', 'driver_assigned_uuid' => '44444444-4444-4444-8444-444444444444', 'tracking_number_uuid' => 'tn-1', 'dispatched' => 0]);

    $order = Order::query()->where('uuid', 'order-1')->first();

    expect($order->loadAssignedDriver()->driverAssigned?->uuid)->toBe('44444444-4444-4444-8444-444444444444')
        ->and($order->resolveDynamicValue('untracked-property'))->toBe('untracked-property')
        ->and($order->resolveDynamicNotifiable('company')?->uuid)->toBe('company-1')
        ->and($order->hasDispatchedStatus())->toBeTrue()
        ->and($order->tracker())->toBeInstanceOf(OrderTracker::class);

    // Assigning the already-assigned driver by public id is a no-op
    expect($order->assignDriver('driver_ordhelp1'))->toBe($order);

    // First dispatch marks the order dispatched exactly once
    $GLOBALS['fleetopsOrderModelDispatches'] = [];
    $order->firstDispatch();
    expect((int) $connection->table('orders')->value('dispatched'))->toBe(1)
        ->and($GLOBALS['fleetopsOrderModelDispatches'])->toHaveCount(1);

    $order->firstDispatch();
    expect($GLOBALS['fleetopsOrderModelDispatches'])->toHaveCount(1);
});
