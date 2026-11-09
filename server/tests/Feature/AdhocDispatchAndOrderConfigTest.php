<?php

use Fleetbase\FleetOps\Console\Commands\CreateOrderConfig;
use Fleetbase\FleetOps\Jobs\ExpireOrderOffer;
use Fleetbase\FleetOps\Jobs\RunOrderConfigAction;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\OrderUpdate;
use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Fleetbase\FleetOps\Support\AdhocDispatch\BroadcastStrategy;
use Fleetbase\FleetOps\Support\AdhocDispatch\SequentialStrategy;
use Fleetbase\FleetOps\Support\DriverMatching;
use Fleetbase\FleetOps\Support\DriverRatings;
use Fleetbase\FleetOps\Support\OrderChat;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\NotifyCustomer;
use Fleetbase\FleetOps\Support\OrderConfig\Actions\NotifyDriver;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTemplates;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\FleetOps\Support\OrderRepricing;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Adhoc dispatch and order config behaviour against SQLite: the shared driver filters (company,
 * vehicle type, busy drivers under the matching policy), offering an order to the nearest driver,
 * accepting, declining, expiring and withdrawing offers, what happens when nobody is left, the
 * expiry job, config triggers and actions, repricing a completed order, the customer's chat,
 * rating and share links, and the create order config command.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\event')) {
    eval('namespace Fleetbase\FleetOps\Support; function event($event = null) { if (\AdhocCfgRecorder::$failEvents) { throw new \RuntimeException(\'socket down\'); } \AdhocCfgRecorder::$events[] = $event; return $event; }');
}

class AdhocCfgRecorder
{
    public static array $notified  = [];
    public static array $sent      = [];
    public static array $events    = [];
    public static bool $failEvents = false;
}

/** An order whose cancellation is recorded rather than run, and whose pickup is set directly. */
class AdhocCfgOrder extends Order
{
    public static ?Point $pickupPoint = null;
    public static array $canceled     = [];

    public function getPickupLocation(): mixed
    {
        return static::$pickupPoint;
    }

    public function cancelWithReason(?string $reason = null)
    {
        static::$canceled[] = [$this->uuid, $reason];

        return null;
    }
}

/** A point as MySQL stores it (SRID, then WKB), which the spatial models read back. */
function adhocCfgWkb(float $longitude, float $latitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('e', $longitude) . pack('e', $latitude);
}

/** [longitude, latitude] of a stored point or of WKT text. */
function adhocCfgPoint(?string $value): array
{
    if ($value !== null && preg_match('/POINT\(\s*([-0-9.]+)\s+([-0-9.]+)\s*\)/i', $value, $m)) {
        return [(float) $m[1], (float) $m[2]];
    }
    if ($value === null || strlen($value) < 25) {
        return [0.0, 0.0];
    }
    $xy = unpack('ex/ey', substr($value, 9, 16));

    return [$xy['x'], $xy['y']];
}

/** The policies the test config starts with: one driver at a time, 15 seconds per offer. */
function adhocCfgBasePolicies(): array
{
    return ['dispatch' => ['adhoc' => ['strategy' => 'sequential', 'offer_timeout' => 15]]];
}

function adhocCfgBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axis = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axis = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_X', fn ($wkt) => adhocCfgPoint($wkt)[0]);
    $pdo->sqliteCreateFunction('ST_Y', fn ($wkt) => adhocCfgPoint($wkt)[1]);
    $pdo->sqliteCreateFunction('st_distance_sphere', function ($a, $b) {
        [$lng1, $lat1] = adhocCfgPoint($a);
        [$lng2, $lat2] = adhocCfgPoint($b);
        $rad           = M_PI / 180;
        $h             = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return 2 * 6371000 * asin(sqrt($h));
    });
    $pdo->sqliteCreateFunction('LOWER', fn ($value) => $value === null ? null : strtolower((string) $value));

    $connection = new SQLiteConnection($pdo);
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
    app()->instance('responsecache', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.default_auth_driver', 'web');
    app()->bind(Illuminate\Contracts\Config\Repository::class, fn () => config());
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));

    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class {
        public function send($notifiables, $notification)
        {
            if (AdhocCfgRecorder::$notified === ['throw']) {
                throw new RuntimeException('push failed');
            }
            AdhocCfgRecorder::$notified[] = [$notifiables->uuid, $notification::class];
            AdhocCfgRecorder::$sent[]     = $notification;
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
        'companies'           => ['uuid', 'public_id', 'name', 'options'],
        'users'               => ['uuid', 'public_id', 'company_uuid', 'name', 'type', 'status', 'email', 'phone'],
        'drivers'             => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid', 'status', 'online', 'location', 'current_job_uuid', 'meta', 'rating_average', 'rating_count'],
        'vehicles'            => ['uuid', 'public_id', 'company_uuid', 'type', 'plate_number', 'seating_capacity', 'vendor_uuid', 'photo_uuid', 'avatar_url', 'name', 'internal_id', 'location', 'online', 'speed', 'heading', 'altitude', 'year', 'make', 'model', 'class', 'color', 'call_sign', 'status', 'specs', 'vin_data', 'telematics', 'meta', 'slug', 'vin', 'trim', 'plate_number_type'],
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'adhoc_strategy', 'required_vehicle_type', 'chat_channel_uuid', 'tracking_number_uuid', 'dispatched_at', 'started_at', 'customer_uuid', 'customer_type', 'purchase_rate_uuid', 'transaction_uuid', 'order_config_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status', 'type', 'adhoc', 'adhoc_distance', 'dispatched', 'started', 'meta'],
        'order_configs'       => ['uuid', 'public_id', 'company_uuid', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'policies', 'triggers', 'version', 'core_service', 'status', 'type', 'tags'],
        'order_offers'        => ['uuid', '_key', 'company_uuid', 'order_uuid', 'driver_uuid', 'status', 'distance', 'expires_at', 'responded_at'],
        'payloads'            => ['uuid', 'public_id', 'company_uuid', 'pickup_uuid', 'dropoff_uuid', 'current_waypoint_uuid', 'meta', 'payment_method'],
        'places'              => ['uuid', 'public_id', 'company_uuid', 'name', 'location', 'meta', 'owner_uuid', 'owner_type'],
        'waypoints'           => ['uuid', 'payload_uuid', 'place_uuid', 'order'],
        'entities'            => ['uuid', 'payload_uuid', 'name'],
        'tracking_statuses'   => ['uuid', 'company_uuid', 'tracking_number_uuid', 'code', 'status'],
        'tracking_numbers'    => ['uuid', 'public_id', 'company_uuid', 'tracking_number', 'owner_uuid', 'status_uuid'],
        'contacts'            => ['uuid', '_key', 'public_id', 'company_uuid', 'user_uuid', 'name', 'email', 'phone', 'type', 'photo_uuid', 'meta'],
        'chat_channels'       => ['uuid', '_key', 'public_id', 'company_uuid', 'created_by_uuid', 'name', 'slug', 'meta'],
        'chat_participants'   => ['uuid', '_key', 'public_id', 'company_uuid', 'chat_channel_uuid', 'user_uuid', 'meta'],
        'chat_messages'       => ['uuid', '_key', 'public_id', 'company_uuid', 'chat_channel_uuid', 'sender_uuid', 'content', 'meta'],
        'chat_attachments'    => ['uuid', 'public_id', 'company_uuid', 'chat_channel_uuid', 'chat_message_uuid', 'sender_uuid', 'file_uuid'],
        'chat_logs'           => ['uuid', 'public_id', 'company_uuid', 'chat_channel_uuid', 'initiator_uuid', 'event_type', 'content', 'subjects', 'meta', 'status'],
        'vendors'             => ['uuid', 'public_id', 'company_uuid', 'name'],
        'files'               => ['uuid', 'public_id', 'company_uuid', 'type', 'path', 'disk', 'bucket', 'original_filename', 'content_type', 'file_size', 'meta'],
        'chat_receipts'       => ['uuid', 'public_id', 'company_uuid', 'chat_message_uuid', 'participant_uuid', 'read_at'],
        'positions'           => ['uuid', '_key', 'public_id', 'company_uuid', 'order_uuid', 'subject_uuid', 'subject_type', 'coordinates', 'heading', 'speed'],
        'service_rates'       => ['uuid', 'public_id', 'company_uuid', 'service_name', 'service_type', 'base_fee', 'per_meter_flat_rate_fee', 'per_meter_unit', 'rate_calculation_method', 'algorithm', 'currency', 'has_cod_fee', 'has_peak_hours_fee'],
        'driver_ratings'      => ['uuid', '_key', 'company_uuid', 'order_uuid', 'driver_uuid', 'customer_uuid', 'customer_type', 'stars', 'comment'],
        'service_quotes'      => ['uuid', '_key', 'distance', 'time', 'public_id', 'company_uuid', 'request_id', 'service_rate_uuid', 'payload_uuid', 'amount', 'currency', 'meta', 'expired_at'],
        'service_quote_items' => ['uuid', '_key', 'public_id', 'service_quote_uuid', 'amount', 'currency', 'details', 'code'],
        'purchase_rates'      => ['uuid', '_key', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'service_quote_uuid', 'transaction_uuid', 'payload_uuid', 'status', 'meta'],
        'transactions'        => ['uuid', 'public_id', 'company_uuid', 'subject_uuid', 'subject_type', 'context_uuid', 'context_type', 'direction', 'type', 'status', 'settlement_status', 'amount', 'currency', 'meta'],
        'custom_fields'       => ['uuid', 'public_id', 'company_uuid', 'category_uuid', 'subject_uuid', 'subject_type', 'name', 'label', 'type', 'for', 'component', 'options', 'required', 'editable', 'default_value', 'validation_rules', 'meta', 'description', 'help_text', 'order'],
        'custom_field_values' => ['uuid', 'public_id', 'company_uuid', 'custom_field_uuid', 'subject_uuid', 'subject_type', 'value', 'value_type'],
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
    $connection->table('companies')->insert([['uuid' => 'company-1', 'public_id' => 'company_one', 'name' => 'Acme Cabs'], ['uuid' => 'company-2', 'public_id' => 'company_two', 'name' => 'Other Cabs']]);
    $passenger = OrderConfigTemplates::attributes('passenger');
    $connection->table('order_configs')->insert([
        'uuid'         => 'config-adhoc',
        'public_id'    => 'order_config_passenger',
        'company_uuid' => 'company-1',
        'name'         => $passenger['name'],
        'key'          => $passenger['key'],
        'namespace'    => $passenger['namespace'],
        'flow'         => json_encode($passenger['flow']),
        'meta'         => json_encode($passenger['meta']),
        'policies'     => json_encode(adhocCfgBasePolicies()),
        'triggers'     => json_encode([]),
    ]);
    $connection->table('places')->insert(['uuid' => 'place-pickup', 'company_uuid' => 'company-1', 'name' => 'Raffles Place', 'location' => adhocCfgWkb(103.8511, 1.2841)]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-pickup']);
    $connection->table('vehicles')->insert([
        ['uuid' => 'car-economy', 'company_uuid' => 'company-1', 'type' => 'Economy'],
        ['uuid' => 'car-xl', 'company_uuid' => 'company-1', 'type' => 'xl'],
    ]);

    OrderConfigTriggers::$runImmediately                = true;
    AdhocCfgRecorder::$notified                         = [];
    AdhocCfgRecorder::$sent                             = [];
    Fleetbase\TestSupport\DispatchRecorder::$dispatched = [];
    AdhocCfgOrder::$canceled                            = [];
    AdhocCfgOrder::$pickupPoint                         = new Point(1.2841, 103.8511);

    return $connection;
}

/** Store policies on the test config, over the ones it starts with. */
function adhocCfgPolicies(SQLiteConnection $connection, array $policies): void
{
    $connection->table('order_configs')->where('uuid', 'config-adhoc')->update(['policies' => json_encode(array_replace_recursive(adhocCfgBasePolicies(), $policies))]);
}

/** Store the test config's triggers. */
function adhocCfgTriggers(SQLiteConnection $connection, array $triggers): void
{
    $connection->table('order_configs')->where('uuid', 'config-adhoc')->update(['triggers' => json_encode($triggers)]);
}

/** Drivers around the pickup at Raffles Place, nearest first: Amara 545 m, Chen 1.2 km, Ben (XL) 210 m. */
function adhocCfgSeedDrivers(SQLiteConnection $connection): void
{
    $connection->table('drivers')->insert([
        ['uuid' => 'amara', 'public_id' => 'driver_amara', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => adhocCfgWkb(103.8511, 1.2890)],
        ['uuid' => 'chen', 'public_id' => 'driver_chen', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => adhocCfgWkb(103.8511, 1.2950)],
        ['uuid' => 'ben', 'public_id' => 'driver_ben', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-xl', 'status' => 'available', 'online' => 1, 'location' => adhocCfgWkb(103.8511, 1.2860)],
        ['uuid' => 'far', 'public_id' => 'driver_far', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => adhocCfgWkb(103.9915, 1.3644)],
        ['uuid' => 'offline', 'public_id' => 'driver_offline', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 0, 'location' => adhocCfgWkb(103.8511, 1.2845)],
        ['uuid' => 'rival', 'public_id' => 'driver_rival', 'company_uuid' => 'company-2', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => adhocCfgWkb(103.8511, 1.2842)],
    ]);
}

function adhocCfgOrder(SQLiteConnection $connection, array $attributes = []): AdhocCfgOrder
{
    $connection->table('orders')->insert(array_merge([
        'uuid'                  => 'order-1',
        'public_id'             => 'order_one',
        'company_uuid'          => 'company-1',
        'order_config_uuid'     => 'config-adhoc',
        'payload_uuid'          => 'payload-1',
        'status'                => 'dispatched',
        'type'                  => 'passenger',
        'adhoc'                 => 1,
        'adhoc_distance'        => 6000,
        'dispatched'            => 1,
        'started'               => 0,
        'required_vehicle_type' => 'economy',
    ], $attributes));

    return adhocCfgFresh($attributes['uuid'] ?? 'order-1');
}

function adhocCfgFresh(string $uuid = 'order-1'): AdhocCfgOrder
{
    return AdhocCfgOrder::where('uuid', $uuid)->first();
}

function adhocCfgDriver(string $uuid): Driver
{
    return Driver::withoutGlobalScopes()->where('uuid', $uuid)->first();
}

function adhocCfgStatuses(SQLiteConnection $connection): array
{
    return $connection->table('order_offers')->orderBy('id')->get(['driver_uuid', 'status'])->map(fn ($row) => [$row->driver_uuid, $row->status])->all();
}

function adhocCfgSequential(): SequentialStrategy
{
    return new SequentialStrategy();
}

test('drivers are matched by company and vehicle type, and busy drivers are left out only under the matching policy', function () {
    $connection = adhocCfgBoot();
    $connection->table('users')->insert(['uuid' => 'user-moved', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'job-live', 'company_uuid' => 'company-1', 'status' => 'started', 'deleted_at' => null],
        ['uuid' => 'job-done', 'company_uuid' => 'company-1', 'status' => 'completed', 'deleted_at' => null],
        ['uuid' => 'job-gone', 'company_uuid' => 'company-1', 'status' => 'started', 'deleted_at' => now()],
        ['uuid' => 'job-noshow', 'company_uuid' => 'company-1', 'status' => 'no_show', 'deleted_at' => null],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'free', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'via-user', 'company_uuid' => 'company-9', 'user_uuid' => 'user-moved', 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'other-company', 'company_uuid' => 'company-2', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'busy', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-live'],
        ['uuid' => 'finished', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-done'],
        ['uuid' => 'stale', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-gone'],
        ['uuid' => 'no-show', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-noshow'],
        ['uuid' => 'big-car', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-xl', 'current_job_uuid' => null],
    ]);
    $match = fn (Order $order) => DriverMatching::forOrder(Driver::query()->withoutGlobalScopes(), $order)->orderBy('id')->pluck('uuid')->all();

    // An order without a config of its own has no matching policy: busy drivers aren't left out.
    $unconfigured = new Order();
    $unconfigured->setRawAttributes(['company_uuid' => 'company-1', 'required_vehicle_type' => ' ECONOMY '], true);
    $anyClass = new Order();
    $anyClass->setRawAttributes(['company_uuid' => 'company-1', 'required_vehicle_type' => '  '], true);

    expect($match($unconfigured))->toBe(['free', 'via-user', 'busy', 'finished', 'stale', 'no-show'])
        ->and($match($anyClass))->toBe(['free', 'via-user', 'busy', 'finished', 'stale', 'no-show', 'big-car'])
        ->and(DriverMatching::vehicleClass($anyClass))->toBeNull()
        ->and(DriverMatching::vehicleClass($unconfigured))->toBe('ECONOMY');

    // The config's policy is off by default.
    $configured = new Order();
    $configured->setRawAttributes(['company_uuid' => 'company-1', 'order_config_uuid' => 'config-adhoc', 'required_vehicle_type' => 'economy'], true);
    expect($match($configured))->toBe(['free', 'via-user', 'busy', 'finished', 'stale', 'no-show']);

    // With it on, a driver on an unfinished order is left out. An order at one of the company
    // configs' terminal activities (no_show here) doesn't keep its driver busy.
    adhocCfgPolicies($connection, ['dispatch' => ['matching' => ['exclude_busy_drivers' => true]]]);
    $configured = new Order();
    $configured->setRawAttributes(['company_uuid' => 'company-1', 'order_config_uuid' => 'config-adhoc', 'required_vehicle_type' => 'economy'], true);

    expect($match($configured))->toBe(['free', 'via-user', 'finished', 'stale', 'no-show'])
        ->and(DriverMatching::freeJobStatuses('company-1'))->toBe(['completed', 'canceled', 'no_show'])
        ->and(DriverMatching::freeJobStatuses('company-2'))->toBe(['completed', 'canceled'])
        ->and(DriverMatching::freeJobStatuses(null))->toBe(['completed', 'canceled']);
});

test('one at a time: the order goes to the nearest free driver of its type first', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order = adhocCfgOrder($connection);

    Carbon::setTestNow('2026-11-06 09:00:00');
    adhocCfgSequential()->dispatch($order);
    Carbon::setTestNow();
    $offer = OrderOffers::pending($order);

    expect(adhocCfgSequential()->name())->toBe('sequential')
        ->and($offer->driver_uuid)->toBe('amara')
        ->and($offer->distance)->toBeGreaterThan(500)->toBeLessThan(600)
        ->and($offer->expires_at->toDateTimeString())->toBe('2026-11-06 09:00:15')
        ->and(AdhocCfgRecorder::$notified)->toBe([['amara', OrderPing::class]])
        ->and(Fleetbase\TestSupport\DispatchRecorder::$dispatched)->toBe([['job' => ExpireOrderOffer::class, 'arguments' => [$offer->uuid]]])
        ->and($offer->order->uuid)->toBe('order-1')
        ->and($offer->driver->uuid)->toBe('amara');

    // Dispatching again keeps the open offer instead of offering it twice.
    adhocCfgSequential()->dispatch($order);
    expect(adhocCfgStatuses($connection))->toBe([['amara', 'pending']]);
});

test('one at a time: declining moves the order on, and only the offered driver may accept', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order = adhocCfgOrder($connection);
    adhocCfgSequential()->dispatch($order);

    expect(adhocCfgSequential()->accept($order, adhocCfgDriver('chen')))->toBeFalse()
        ->and(adhocCfgSequential()->decline($order, adhocCfgDriver('chen')))->toBeFalse()
        ->and(adhocCfgSequential()->decline($order, adhocCfgDriver('amara')))->toBeTrue()
        ->and(OrderOffers::pending($order)->driver_uuid)->toBe('chen')
        ->and(adhocCfgSequential()->accept($order, adhocCfgDriver('amara')))->toBeFalse()
        ->and(adhocCfgSequential()->accept($order, adhocCfgDriver('chen')))->toBeTrue()
        ->and(adhocCfgStatuses($connection))->toBe([['amara', 'declined'], ['chen', 'accepted']]);
});

test('one at a time: an offer that ran out cannot be accepted and moves on once', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order = adhocCfgOrder($connection);
    adhocCfgSequential()->dispatch($order);
    $offer = OrderOffers::pending($order);

    expect(adhocCfgSequential()->expire($offer))->toBeFalse();

    Carbon::setTestNow(now()->addSeconds(30));
    $acceptedLate = adhocCfgSequential()->accept($order, adhocCfgDriver('amara'));
    $first        = (new ExpireOrderOffer($offer->uuid))->handle();
    $again        = (new ExpireOrderOffer($offer->uuid))->handle();
    $missing      = (new ExpireOrderOffer('no-such-offer'))->handle();
    Carbon::setTestNow();

    expect($acceptedLate)->toBeFalse()
        ->and($first)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($missing)->toBeFalse()
        ->and(adhocCfgStatuses($connection))->toBe([['amara', 'expired'], ['chen', 'pending']]);
});

test('one at a time: the scheduled check starts lost offers and moves stuck ones on', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order = adhocCfgOrder($connection);

    expect(adhocCfgSequential()->redispatch($order))->toBe(1)
        ->and(adhocCfgSequential()->redispatch($order))->toBe(0);

    Carbon::setTestNow(now()->addMinute());
    expect(adhocCfgSequential()->redispatch($order))->toBe(1);
    Carbon::setTestNow();

    $connection->table('order_offers')->update(['status' => 'declined']);
    expect(adhocCfgSequential()->redispatch($order))->toBe(0)
        ->and(adhocCfgStatuses($connection))->toBe([['amara', 'declined'], ['chen', 'declined']]);
});

test('one at a time: when nobody is left the order stays open by default, marked no driver found, and the exhausted triggers run', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    adhocCfgTriggers($connection, [
        ['event' => OrderConfigTriggers::DISPATCH_OFFER_EXHAUSTED, 'action' => 'set_meta', 'params' => ['key' => 'exhausted_seen', 'value' => 'yes']],
        ['event' => OrderConfigTriggers::ORDER_CANCELED, 'action' => 'set_meta', 'params' => ['key' => 'canceled_seen', 'value' => 'yes']],
    ]);
    $order = adhocCfgOrder($connection);
    adhocCfgSequential()->dispatch($order);
    adhocCfgSequential()->decline($order, adhocCfgDriver('amara'));
    adhocCfgSequential()->decline($order, adhocCfgDriver('chen'));
    $open = adhocCfgFresh();

    expect(AdhocCfgOrder::$canceled)->toBe([])
        ->and($open->status)->toBe('dispatched')
        ->and($open->getMeta('dispatch_outcome'))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and($open->getMeta('exhausted_seen'))->toBe('yes')
        ->and($open->getMeta('canceled_seen'))->toBeNull()
        ->and(OrderOffers::outcome($open))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and($open->dispatchOutcome())->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and(OrderOffers::pending($open))->toBeNull();

    // A later scheduled run that finds nobody again doesn't fire the triggers a second time.
    $open->updateMeta('exhausted_seen', 'cleared');
    OrderOffers::exhausted($open);
    expect(adhocCfgFresh()->getMeta('exhausted_seen'))->toBe('cleared');

    // Once the operator assigns a driver it is no longer an order nobody took.
    $connection->table('orders')->where('uuid', 'order-1')->update(['driver_assigned_uuid' => 'ben']);
    expect(OrderOffers::outcome(adhocCfgFresh()))->toBeNull();
});

test('one at a time: when nobody is left an order whose policy says cancel is canceled as no driver found', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    adhocCfgPolicies($connection, ['dispatch' => ['adhoc' => ['on_exhausted' => 'cancel']]]);
    $order = adhocCfgOrder($connection);
    adhocCfgSequential()->dispatch($order);
    adhocCfgSequential()->decline($order, adhocCfgDriver('amara'));
    adhocCfgSequential()->decline($order, adhocCfgDriver('chen'));

    // The cancel itself is recorded by the test order; mark it canceled as the real one would.
    $connection->table('orders')->where('uuid', 'order-1')->update(['status' => 'canceled']);

    expect(AdhocCfgOrder::$canceled)->toBe([['order-1', OrderOffers::NO_DRIVER_FOUND]])
        ->and(OrderOffers::outcome(adhocCfgFresh()))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and(OrderOffers::pending($order))->toBeNull();

    // Without the meta, a canceled order's offers tell: all declined or expired is no driver found,
    // while one canceled with an offer still open (withdrawn) wasn't for want of a driver.
    $declined = adhocCfgOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_two', 'status' => 'canceled']);
    $withdrew = adhocCfgOrder($connection, ['uuid' => 'order-3', 'public_id' => 'order_three', 'status' => 'canceled']);
    $connection->table('order_offers')->insert([
        ['uuid' => 'f-2a', 'order_uuid' => 'order-2', 'driver_uuid' => 'amara', 'status' => 'declined'],
        ['uuid' => 'f-2b', 'order_uuid' => 'order-2', 'driver_uuid' => 'chen', 'status' => 'expired'],
        ['uuid' => 'f-3a', 'order_uuid' => 'order-3', 'driver_uuid' => 'amara', 'status' => 'declined'],
        ['uuid' => 'f-3b', 'order_uuid' => 'order-3', 'driver_uuid' => 'chen', 'status' => 'withdrawn'],
    ]);
    $notAdhoc = new Order();
    $notAdhoc->setRawAttributes(['uuid' => 'order-2', 'status' => 'canceled', 'adhoc' => 0], true);
    $live = adhocCfgOrder($connection, ['uuid' => 'order-4', 'public_id' => 'order_four']);

    expect(OrderOffers::outcome($declined))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and(OrderOffers::outcome($withdrew))->toBeNull()
        ->and(OrderOffers::outcome($notAdhoc))->toBeNull()
        ->and(OrderOffers::outcome($live))->toBeNull();
});

test('one at a time: an order without a usable pickup finds no driver and stays open by default', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    AdhocCfgOrder::$pickupPoint = null;

    adhocCfgSequential()->dispatch(adhocCfgOrder($connection));

    expect(AdhocCfgOrder::$canceled)->toBe([])
        ->and(adhocCfgFresh()->getMeta('dispatch_outcome'))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and(adhocCfgStatuses($connection))->toBe([]);

    adhocCfgPolicies($connection, ['dispatch' => ['adhoc' => ['on_exhausted' => 'cancel']]]);
    adhocCfgSequential()->dispatch(adhocCfgOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_two']));
    expect(AdhocCfgOrder::$canceled)->toBe([['order-2', OrderOffers::NO_DRIVER_FOUND]]);
});

test('one at a time: started, assigned or finished orders are not offered again', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);

    $started  = adhocCfgOrder($connection, ['uuid' => 'o-started', 'started' => 1]);
    $assigned = adhocCfgOrder($connection, ['uuid' => 'o-assigned', 'driver_assigned_uuid' => 'amara']);
    $canceled = adhocCfgOrder($connection, ['uuid' => 'o-canceled', 'status' => 'canceled']);

    expect(adhocCfgSequential()->offerNext($started))->toBeNull()
        ->and(adhocCfgSequential()->offerNext($assigned))->toBeNull()
        ->and(adhocCfgSequential()->offerNext($canceled))->toBeNull()
        ->and(adhocCfgStatuses($connection))->toBe([])
        ->and(AdhocCfgOrder::$canceled)->toBe([]);
});

test('one at a time: a failed push still leaves the offer open', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order                      = adhocCfgOrder($connection);
    AdhocCfgRecorder::$notified = ['throw'];

    adhocCfgSequential()->dispatch($order);

    expect(OrderOffers::pending($order)->driver_uuid)->toBe('amara')
        ->and(app('log')->warnings)->toContain('Unable to send an order offer');
});

test('at once: every nearby driver of the type is offered the order, and the first to accept gets it', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order     = adhocCfgOrder($connection, ['adhoc_strategy' => 'broadcast']);
    $broadcast = new BroadcastStrategy();

    $broadcast->dispatch($order);

    expect($broadcast->name())->toBe('broadcast')
        ->and(adhocCfgStatuses($connection))->toBe([['amara', 'pending'], ['chen', 'pending']])
        ->and(AdhocCfgRecorder::$notified)->toBe([['amara', OrderPing::class], ['chen', OrderPing::class]])
        ->and($broadcast->accept($order, adhocCfgDriver('ben')))->toBeTrue();

    OrderOffers::claimed($order, adhocCfgDriver('chen'));
    expect(adhocCfgStatuses($connection))->toBe([['amara', 'withdrawn'], ['chen', 'accepted']]);
});

test('at once: a driver who declines is not offered the order again', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order     = adhocCfgOrder($connection, ['adhoc_strategy' => 'broadcast', 'required_vehicle_type' => null]);
    $broadcast = new BroadcastStrategy();
    $broadcast->dispatch($order);

    // Ben declines his offer; Far, never offered, declines from the list of nearby orders.
    expect($broadcast->decline($order, adhocCfgDriver('ben')))->toBeTrue()
        ->and($broadcast->decline($order, adhocCfgDriver('far')))->toBeTrue()
        ->and($broadcast->decline($order, adhocCfgDriver('far')))->toBeTrue();

    Carbon::setTestNow('2026-11-06 10:00:00');
    $offered = $broadcast->redispatch($order);
    Carbon::setTestNow();

    expect($offered)->toBe(2)
        ->and(OrderOffers::declinedDrivers($order))->toBe(['ben', 'far'])
        ->and($connection->table('order_offers')->where('status', 'pending')->orderBy('id')->pluck('driver_uuid')->all())->toBe(['amara', 'chen'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('dispatched_at'))->toBe('2026-11-06 10:00:00');
});

test('at once: no pickup means nobody to offer, and a failed push skips only that driver', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $broadcast = new BroadcastStrategy();

    AdhocCfgOrder::$pickupPoint = null;
    expect($broadcast->redispatch(adhocCfgOrder($connection, ['uuid' => 'o-nowhere'])))->toBe(0);

    AdhocCfgOrder::$pickupPoint = new Point(1.2841, 103.8511);
    AdhocCfgRecorder::$notified = ['throw'];
    expect($broadcast->redispatch(adhocCfgOrder($connection)))->toBe(2);
});

test('which strategy an order uses: its own, its config\'s adhoc policy, its company\'s, then one at a time', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgOrder($connection, ['adhoc_strategy' => 'broadcast']);
    $fromConfig = adhocCfgOrder($connection, ['uuid' => 'o-config']);
    $configName = AdhocDispatch::nameFor($fromConfig);
    $configWait = AdhocDispatch::offerTimeout($fromConfig);
    // Written straight to the table, past the model's normalizing, as an old or hand-edited row could be.
    $connection->table('order_configs')->update(['policies' => json_encode(['dispatch' => ['adhoc' => ['strategy' => 'nonsense', 'offer_timeout' => null]]])]);
    $connection->table('companies')->where('uuid', 'company-1')->update(['options' => json_encode(['fleetops' => ['adhoc_strategy' => 'broadcast', 'adhoc_offer_timeout' => 500]])]);
    $fromCompany = adhocCfgFresh('o-config')->load('company');
    $connection->table('companies')->update(['options' => null]);
    $fallback = adhocCfgFresh('o-config');
    // An order without a config of its own has no policies, so goes straight to the company's.
    $unconfigured = adhocCfgOrder($connection, ['uuid' => 'o-none', 'order_config_uuid' => null]);

    expect(AdhocDispatch::nameFor($order))->toBe('broadcast')
        ->and(AdhocDispatch::for($order))->toBeInstanceOf(BroadcastStrategy::class)
        ->and($configName)->toBe('sequential')
        ->and($configWait)->toBe(15)
        ->and(AdhocDispatch::nameFor($fromCompany))->toBe('broadcast')
        ->and(AdhocDispatch::offerTimeout($fromCompany))->toBe(120)
        ->and(AdhocDispatch::nameFor($fallback))->toBe(AdhocDispatch::DEFAULT)
        ->and(AdhocDispatch::offerTimeout($fallback))->toBe(20)
        ->and(AdhocDispatch::nameFor($unconfigured))->toBe(AdhocDispatch::DEFAULT)
        ->and(AdhocDispatch::DEFAULT)->toBe('sequential');

    $connection->table('order_configs')->update(['policies' => json_encode(['dispatch' => ['adhoc' => ['offer_timeout' => 2]]])]);
    expect(AdhocDispatch::offerTimeout(adhocCfgFresh('o-config')))->toBe(10);
});

test('more strategies can be registered by name', function () {
    $nearestOnly = new class extends SequentialStrategy {
        public function name(): string
        {
            return 'nearest-only';
        }
    };
    AdhocDispatch::register('nearest-only', $nearestOnly::class);

    expect(AdhocDispatch::names())->toContain('broadcast', 'sequential', 'nearest-only')
        ->and(AdhocDispatch::has('nearest-only'))->toBeTrue()
        ->and(AdhocDispatch::has(null))->toBeFalse()
        ->and(fn () => AdhocDispatch::register('broken', stdClass::class))->toThrow(InvalidArgumentException::class);
});

test('offers record each driver once, and a decline from a driver who was never offered the order', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order = adhocCfgOrder($connection);
    $amara = adhocCfgDriver('amara');

    $first = OrderOffers::record($order, $amara);
    $again = OrderOffers::record($order, $amara);
    OrderOffers::recordDecline($order, adhocCfgDriver('ben'));

    expect($again->uuid)->toBe($first->uuid)
        ->and($first->expires_at)->toBeNull()
        ->and(OrderOffers::offeredDrivers($order))->toBe(['amara', 'ben'])
        ->and(OrderOffers::acceptPending($order, $amara))->toBeTrue()
        ->and(OrderOffers::withdraw($order))->toBe(0);
});

test('the passenger config is a strict flow with a no-show ending, offered one driver at a time', function () {
    $attributes = OrderConfigTemplates::attributes('passenger');
    $config     = new OrderConfig($attributes);

    expect($attributes['key'])->toBe('passenger')
        ->and(array_keys($attributes['flow']))->toBe(['created', 'dispatched', 'started', 'arrived', 'in_progress', 'completed', 'no_show'])
        ->and($config->policy('dispatch.adhoc.strategy'))->toBe('sequential')
        ->and($config->policy('dispatch.adhoc.customer_orders'))->toBeTrue()
        ->and($config->policy('dispatch.matching.vehicle_type'))->toBeTrue()
        ->and($config->hasStrictTransitions())->toBeTrue()
        ->and($config->canTransition('arrived', 'no_show'))->toBeTrue()
        ->and($config->canTransition('arrived', 'completed'))->toBeFalse()
        ->and($config->canTransition('in_progress', 'completed'))->toBeTrue()
        ->and($config->isTerminalActivityCode('no_show'))->toBeTrue()
        ->and($config->getStartedActivity()->get('status'))->toBe('Driver on the way');
});

class AdhocCfgCreateCommand extends CreateOrderConfig
{
    public array $lines  = [];
    public array $tables = [];
    public ?string $key  = null;
    public array $opts   = [];

    public function option($key = null)
    {
        return $this->opts[$key] ?? null;
    }

    public function argument($key = null)
    {
        return $this->key;
    }

    public function info($string, $verbosity = null)
    {
        $this->lines[] = $string;
    }

    public function error($string, $verbosity = null)
    {
        $this->lines[] = $string;
    }

    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
    {
        $this->tables[] = [$headers, $rows];
    }
}

test('the create order config command lists templates and makes a company\'s config once, or a named one', function () {
    $connection = adhocCfgBoot();
    $connection->table('order_configs')->delete();
    $run = function (?string $key, array $opts = []) {
        $command       = new AdhocCfgCreateCommand();
        $command->key  = $key;
        $command->opts = $opts;

        return [$command->handle(), $command];
    };

    [$listed, $list]       = $run('passenger', ['list' => true]);
    [$bare, $bareList]     = $run(null);
    [$unknown, $nope]      = $run('limousine', ['company' => 'company-1']);
    [$noCompany, $none]    = $run('passenger');
    [$missing, $missed]    = $run('passenger', ['company' => 'company_missing']);
    [$made, $madeCommand]  = $run('passenger', ['company' => 'company_one']);
    [$again, $againCmd]    = $run('passenger', ['company' => 'company-1']);
    [$named, $namedCmd]    = $run('passenger', ['company' => 'company-1', 'name' => ' Airport Transfers ']);
    [$transport, $plainly] = $run('transport', ['company' => 'company-1']);

    $system  = $connection->table('order_configs')->where('namespace', 'system:order-config:passenger')->get();
    $airport = $connection->table('order_configs')->where('key', 'airport-transfers')->first();
    $fields  = $connection->table('custom_fields')->orderBy('id')->get();
    $plain   = $connection->table('order_configs')->where('key', 'transport')->value('uuid');

    expect([$listed, $bare, $unknown, $noCompany, $missing, $made, $again, $named, $transport])->toBe([0, 0, 1, 1, 1, 0, 0, 0, 0])
        ->and($list->tables[0][0])->toBe(['Key', 'Name', 'Description'])
        ->and(array_column($list->tables[0][1], 'key'))->toBe(['transport', 'passenger'])
        ->and($bareList->tables)->toHaveCount(1)
        ->and($nope->lines[0])->toBe('No order config template named limousine. Run with --list to see them.')
        ->and($none->lines[0])->toBe('Give the company with --company.')
        ->and($missed->lines[0])->toBe('No company found for company_missing.')
        ->and($madeCommand->lines[0])->toStartWith('Created order config Passenger')->toEndWith('for Acme Cabs.')
        ->and($againCmd->lines[0])->toStartWith('Found order config Passenger')
        ->and($namedCmd->lines[0])->toStartWith('Created order config Airport Transfers')
        ->and($system)->toHaveCount(1)
        ->and($system[0]->key)->toBe('passenger')
        ->and(json_decode($system[0]->policies, true)['dispatch']['adhoc']['strategy'])->toBe('sequential')
        ->and(json_decode($system[0]->triggers, true))->toHaveCount(4)
        ->and($airport->namespace)->toBe('acme-cabs:order-config:airport-transfers')
        ->and((int) $airport->core_service)->toBe(0)
        ->and(json_decode($airport->policies, true))->toBe(json_decode($system[0]->policies, true))
        // The passenger template's custom field is made with each new config, and only then.
        ->and($fields->pluck('name')->all())->toBe(['passengers', 'passengers'])
        ->and($fields->pluck('subject_uuid')->all())->toBe([$system[0]->uuid, $airport->uuid])
        ->and($fields->pluck('company_uuid')->unique()->all())->toBe(['company-1'])
        ->and($plain)->not->toBeNull()
        ->and($connection->table('custom_fields')->where('subject_uuid', $plain)->count())->toBe(0);
});

test('a dispatched adhoc order is offered by its strategy, and a busy driver is offered it while the matching policy is off', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    adhocCfgOrder($connection, ['tracking_number_uuid' => 'tn-1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'DISPATCHED']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(['uuid' => 'job-live', 'company_uuid' => 'company-1', 'status' => 'started']);
    $connection->table('drivers')->where('uuid', 'amara')->update(['current_job_uuid' => 'job-live']);

    (new Fleetbase\FleetOps\Listeners\HandleOrderDispatched())->handle(new Fleetbase\FleetOps\Events\OrderDispatched(adhocCfgFresh()));

    expect(adhocCfgStatuses($connection))->toBe([['amara', 'pending']])
        ->and(AdhocCfgRecorder::$notified)->toBe([['amara', OrderPing::class]]);
});

test('a dispatched adhoc order skips a busy driver when its config excludes busy drivers', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    adhocCfgPolicies($connection, ['dispatch' => ['matching' => ['exclude_busy_drivers' => true]]]);
    adhocCfgOrder($connection, ['tracking_number_uuid' => 'tn-1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'DISPATCHED']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(['uuid' => 'job-live', 'company_uuid' => 'company-1', 'status' => 'started']);
    $connection->table('drivers')->where('uuid', 'amara')->update(['current_job_uuid' => 'job-live']);

    (new Fleetbase\FleetOps\Listeners\HandleOrderDispatched())->handle(new Fleetbase\FleetOps\Events\OrderDispatched(adhocCfgFresh()));

    expect(adhocCfgStatuses($connection))->toBe([['chen', 'pending']])
        ->and(AdhocCfgRecorder::$notified)->toBe([['chen', OrderPing::class]]);
});

test('the scheduled adhoc command asks each order\'s strategy', function () {
    $connection = adhocCfgBoot();
    $lookup     = new ReflectionMethod(Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class, 'strategyFor');
    $command    = new Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders();

    expect($lookup->invoke($command, adhocCfgOrder($connection, ['adhoc_strategy' => 'broadcast'])))->toBeInstanceOf(BroadcastStrategy::class)
        ->and($lookup->invoke($command, adhocCfgOrder($connection, ['uuid' => 'o-2'])))->toBeInstanceOf(SequentialStrategy::class);
});

test('quotes carry each service type\'s seating capacity and how soon its nearest free driver could arrive', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $connection->table('vehicles')->where('uuid', 'car-economy')->update(['seating_capacity' => 4]);
    $connection->table('vehicles')->insert(['uuid' => 'car-economy-2', 'company_uuid' => 'company-1', 'type' => 'economy', 'seating_capacity' => 5]);

    $controller = new class extends Fleetbase\FleetOps\Http\Controllers\Api\v1\ServiceQuoteController {
        public function helper(string $method, ...$arguments): mixed
        {
            return $this->{$method}(...$arguments);
        }

        public function from(?Point $point): void
        {
            $this->availabilityFrom = $point;
        }
    };
    $quote = function (string $class) {
        $rate = new Fleetbase\FleetOps\Models\ServiceRate();
        $rate->setRawAttributes(['service_type' => $class], true);
        $quote = new Fleetbase\FleetOps\Models\ServiceQuote();
        $quote->setRawAttributes(['company_uuid' => 'company-1'], true);
        $quote->setRelation('serviceRate', $rate);

        return $quote;
    };

    $economy = $quote('economy');
    $limo    = $quote('limo');
    $controller->from(new Point(1.2841, 103.8511));
    $controller->helper('serviceQuoteResourceCollection', [$economy, $limo]);

    $asked    = Illuminate\Http\Request::create('/', 'GET', ['availability' => 1]);
    $notAsked = Illuminate\Http\Request::create('/', 'GET');

    expect($economy->availability)->toBe(['seating_capacity' => 5, 'pickup_eta' => 138])
        ->and($limo->availability)->toBe(['seating_capacity' => null, 'pickup_eta' => null])
        ->and($controller->helper('availabilityPoint', $asked, ['latitude' => 1.28, 'longitude' => 103.85]))->toBeInstanceOf(Point::class)
        ->and($controller->helper('availabilityPoint', $asked, 'place_pickup'))->toBeNull()
        ->and($controller->helper('availabilityPoint', $notAsked, ['latitude' => 1.28, 'longitude' => 103.85]))->toBeNull();

    // Without a pickup, quotes are returned as they are.
    $plain = $quote('economy');
    $controller->from(null);
    $controller->helper('serviceQuoteResourceCollection', [$plain]);
    expect($plain->availability)->toBeNull();
});

/** A completed order by customer Sam with driver Chen, from Raffles Place to Bugis. */
function adhocCfgSeedOrder(SQLiteConnection $connection, array $order = []): AdhocCfgOrder
{
    adhocCfgSeedDrivers($connection);
    $connection->table('users')->insert([
        ['uuid' => 'user-sam', 'public_id' => 'user_sam', 'company_uuid' => 'company-1', 'name' => 'Sam Lee', 'type' => 'customer', 'email' => 'sam@customer.test', 'phone' => '+15550190301'],
        ['uuid' => 'user-chen', 'public_id' => 'user_chen', 'company_uuid' => 'company-1', 'name' => 'Chen Test', 'type' => 'driver', 'email' => 'chen@cabs.test', 'phone' => '+15550190203'],
    ]);
    $connection->table('drivers')->where('uuid', 'chen')->update(['user_uuid' => 'user-chen']);
    $connection->table('contacts')->insert(['uuid' => 'contact-sam', 'public_id' => 'contact_sam', 'company_uuid' => 'company-1', 'user_uuid' => 'user-sam', 'name' => 'Sam Lee', 'email' => 'sam@customer.test', 'phone' => '+15550190301', 'type' => 'customer']);
    $connection->table('places')->insert(['uuid' => 'place-dropoff', 'company_uuid' => 'company-1', 'name' => 'Bugis', 'location' => adhocCfgWkb(103.8452, 1.3005)]);
    $connection->table('payloads')->where('uuid', 'payload-1')->update(['dropoff_uuid' => 'place-dropoff']);
    $connection->table('service_rates')->insert(['uuid' => 'rate-economy', 'company_uuid' => 'company-1', 'service_name' => 'Economy', 'service_type' => 'economy', 'base_fee' => 300, 'rate_calculation_method' => 'algo', 'algorithm' => 'max(200, {distance_km} * 120 + {time_min} * 20)', 'currency' => 'USD']);
    $connection->table('service_quotes')->insert(['uuid' => 'quote-booked', 'company_uuid' => 'company-1', 'service_rate_uuid' => 'rate-economy', 'amount' => 533, 'currency' => 'USD']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'TRK1', 'owner_uuid' => 'order-1']);
    $connection->table('purchase_rates')->insert(['uuid' => 'purchase-booked', 'company_uuid' => 'company-1', 'service_quote_uuid' => 'quote-booked', 'payload_uuid' => 'payload-1', 'status' => 'created']);

    return adhocCfgOrder($connection, array_merge([
        'status'               => 'completed',
        'started'              => 1,
        'started_at'           => '2026-11-06 09:00:00',
        'driver_assigned_uuid' => 'chen',
        'customer_uuid'        => 'contact-sam',
        'customer_type'        => 'Fleetbase\\FleetOps\\Models\\Contact',
        'purchase_rate_uuid'   => 'purchase-booked',
        'tracking_number_uuid' => 'tn-1',
    ], $order));
}

test('the order chat opens once a driver is assigned, with just the customer and driver in it, and closes', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection, ['status' => 'started', 'driver_assigned_uuid' => null]);

    expect(OrderChat::open($order))->toBeNull()
        ->and(OrderChat::find($order))->toBeNull()
        ->and(OrderChat::close($order))->toBeFalse();

    $connection->table('orders')->update(['driver_assigned_uuid' => 'chen']);
    $order   = adhocCfgFresh();
    $channel = OrderChat::open($order);
    $again   = OrderChat::open(adhocCfgFresh());

    expect($channel->meta)->toBe(['order_public_id' => 'order_one', 'kind' => 'group'])
        ->and($again->uuid)->toBe($channel->uuid)
        ->and(OrderChat::find(adhocCfgFresh())->uuid)->toBe($channel->uuid)
        ->and($connection->table('chat_channels')->count())->toBe(1)
        ->and($connection->table('chat_participants')->orderBy('id')->pluck('user_uuid')->all())->toBe(['user-sam', 'user-chen'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('chat_channel_uuid'))->toBe($channel->uuid);

    $sent = OrderChat::send($channel, 'user-sam', 'By the lobby');
    $chen = $connection->table('chat_participants')->where('user_uuid', 'user-chen')->value('uuid');
    $connection->table('chat_messages')->insert(['uuid' => 'm-2', 'company_uuid' => 'company-1', 'chat_channel_uuid' => $channel->uuid, 'sender_uuid' => $chen, 'content' => 'Two minutes', 'created_at' => now()->addMinute()]);

    $messages = OrderChat::messages($channel, 'user-sam');
    expect($messages->map(fn ($message) => [$message['from'], $message['text']])->all())->toBe([['customer', 'By the lobby'], ['driver', 'Two minutes']])
        ->and($messages[0]['id'])->toBe($sent->public_id ?? $sent->uuid)
        ->and(OrderChat::isClosed(adhocCfgFresh()))->toBeFalse();

    // A finished order's chat is closed by its config's terminal activities, and closing it marks the channel.
    $connection->table('orders')->update(['status' => 'no_show']);
    expect(OrderChat::isClosed(adhocCfgFresh()))->toBeTrue();
    $connection->table('orders')->update(['status' => 'started']);
    expect(OrderChat::close(adhocCfgFresh()))->toBeTrue()
        ->and(OrderChat::isClosed(adhocCfgFresh()))->toBeTrue()
        ->and(json_decode($connection->table('chat_channels')->value('meta'), true))->toHaveKey('closed_at');
});

test('a customer rates their driver once, and the driver keeps an average', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection);
    $connection->table('driver_ratings')->insert(['uuid' => 'r-old', 'order_uuid' => 'order-old', 'driver_uuid' => 'chen', 'stars' => 4]);

    $first  = DriverRatings::rate($order, 9, ' Great ');
    $second = DriverRatings::rate(adhocCfgFresh(), 1);
    $driver = adhocCfgDriver('chen');
    $rating = Fleetbase\FleetOps\Models\DriverRating::where('order_uuid', 'order-1')->first();

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and([$rating->stars, $rating->comment, $rating->customer_uuid])->toBe([5, 'Great', 'contact-sam'])
        ->and($rating->order->uuid)->toBe('order-1')
        ->and($rating->driver->uuid)->toBe('chen')
        ->and($order->driverRating->stars)->toBe(5)
        ->and([$driver->rating_average, $driver->rating_count])->toBe([4.5, 2]);

    // A driver whose ratings were all removed has no average.
    $connection->table('driver_ratings')->update(['deleted_at' => now()]);
    DriverRatings::refresh('chen');
    expect([adhocCfgDriver('chen')->rating_average, adhocCfgDriver('chen')->rating_count])->toBe([null, 0]);
});

test('two ratings racing for one order keep the first', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection);
    // The other request's rating lands between the check and the insert; the unique index refuses this one.
    $connection->statement("CREATE TRIGGER rating_race BEFORE INSERT ON driver_ratings BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: driver_ratings.order_uuid'); END");

    expect(DriverRatings::rate($order, 5))->toBeFalse()
        ->and(adhocCfgDriver('chen')->rating_count)->toBeNull();
});

test('a share link opens only its customer\'s view of its order, until it expires', function () {
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-1', 'company_uuid' => 'company-1'], true);
    $sam   = new Fleetbase\FleetOps\Models\Contact();
    $sam->setRawAttributes(['uuid' => 'contact-sam'], true);
    $other = new Fleetbase\FleetOps\Models\Contact();
    $other->setRawAttributes(['uuid' => 'contact-other'], true);

    $scope     = Fleetbase\FleetOps\Support\TrackingScope::forCustomer($order, $sam);
    $token     = Fleetbase\FleetOps\Support\TrackingPage\TrackingShare::token('order-1', 'contact-sam');
    $expired   = Fleetbase\FleetOps\Support\TrackingPage\TrackingShare::token('order-1', 'contact-sam', Carbon::now()->subMinute());
    $valid     = fn ($token, $scope) => Fleetbase\FleetOps\Support\TrackingPage\TrackingShare::valid($token, $scope);

    expect($valid($token, $scope))->toBeTrue()
        ->and($valid($token, Fleetbase\FleetOps\Support\TrackingScope::forCustomer($order, $other)))->toBeFalse()
        ->and($valid($expired, $scope))->toBeFalse()
        ->and($valid(substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0'), $scope))->toBeFalse()
        ->and($valid('not-a-token', $scope))->toBeFalse()
        ->and($valid(['array'], $scope))->toBeFalse()
        ->and($valid($token, null))->toBeFalse();
});

test('an order update push carries its title, message and type to the device channels', function () {
    $order = new Order();
    $order->setRawAttributes(['public_id' => 'order_one'], true);

    $update  = new OrderUpdate($order, 'Your driver has arrived', 'Chen is waiting.', 'order_arrived');
    $default = new OrderUpdate($order, 'Title', 'Message');

    expect([$update->title, $update->message])->toBe(['Your driver has arrived', 'Chen is waiting.'])
        ->and($update->data)->toBe(['id' => 'order_one', 'type' => 'order_arrived'])
        ->and($default->data)->toBe(['id' => 'order_one', 'type' => 'order_update'])
        ->and($update->via(null))->toBe([NotificationChannels\Fcm\FcmChannel::class, NotificationChannels\Apn\ApnChannel::class]);
});

test('the notify actions push to the order\'s customer and driver, with placeholders filled in', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection, ['status' => 'arrived']);
    $context    = ['event' => 'order.activity', 'activity' => ['code' => 'arrived', 'status' => 'Driver has arrived', 'details' => 'The driver is waiting at the pickup.']];

    (new NotifyCustomer())->handle($order, ['title' => '{driver.first_name} is here', 'message' => 'Order {order.id} ({order.tracking_number}) for {customer.name}: {order.status}. {nope}'], $context);
    (new NotifyCustomer())->handle($order, [], $context);
    (new NotifyCustomer())->handle($order, [], ['event' => 'order.completed']);
    (new NotifyDriver())->handle($order, ['title' => 'Customer {customer.name} is waiting'], ['event' => OrderConfigTriggers::DRIVER_APPROACHING]);

    $orphan = adhocCfgOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_two', 'customer_uuid' => null, 'driver_assigned_uuid' => null]);
    (new NotifyCustomer())->handle($orphan, ['title' => 'Nobody hears this'], $context);
    (new NotifyDriver())->handle($orphan, ['title' => 'Nobody hears this'], $context);

    $sent = AdhocCfgRecorder::$sent;
    expect(AdhocCfgRecorder::$notified)->toBe([['contact-sam', OrderUpdate::class], ['contact-sam', OrderUpdate::class], ['chen', OrderUpdate::class]])
        ->and([$sent[0]->title, $sent[0]->message, $sent[0]->data['type']])->toBe(['Chen is here', 'Order order_one (TRK1) for Sam Lee: arrived.', 'order_arrived'])
        // Without a title or message of its own, the activity's status and details are used.
        ->and([$sent[1]->title, $sent[1]->message])->toBe(['Driver has arrived', 'The driver is waiting at the pickup.'])
        ->and([$sent[2]->title, $sent[2]->message, $sent[2]->data['type']])->toBe(['Customer Sam Lee is waiting', '', 'order_driver_approaching'])
        ->and(NotifyCustomer::eventType([]))->toBe('order_update');
});

test('reaching an activity runs its actions, and completing or canceling an order fires the config\'s triggers', function () {
    $connection = adhocCfgBoot();
    $passenger  = OrderConfigTemplates::attributes('passenger');
    adhocCfgTriggers($connection, $passenger['triggers']);
    $order    = adhocCfgSeedOrder($connection, ['status' => 'started']);
    $observer = new Fleetbase\FleetOps\Observers\OrderObserver();

    $order->status = 'arrived';
    $order->save();
    $observer->updated($order);

    expect(AdhocCfgRecorder::$notified)->toBe([['contact-sam', OrderUpdate::class]])
        ->and([AdhocCfgRecorder::$sent[0]->title, AdhocCfgRecorder::$sent[0]->message, AdhocCfgRecorder::$sent[0]->data['type']])->toBe(['Your driver has arrived', 'Chen is waiting at the pickup point.', 'order_arrived']);

    // Nothing runs when the status didn't change.
    $observer->updated(adhocCfgFresh());
    expect(AdhocCfgRecorder::$notified)->toHaveCount(1);

    // Canceling fires order.canceled: the customer is told, unless nobody was found for the order.
    $order->status = 'canceled';
    $order->save();
    $observer->updated($order);
    expect(AdhocCfgRecorder::$sent)->toHaveCount(2)
        ->and(AdhocCfgRecorder::$sent[1]->title)->toBe('Your trip was canceled')
        ->and(AdhocCfgRecorder::$sent[1]->data['type'])->toBe('order_order_canceled');

    $quiet         = adhocCfgOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_two', 'status' => 'dispatched', 'customer_uuid' => 'contact-sam', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact', 'meta' => json_encode(['dispatch_outcome' => 'no_driver_found'])]);
    $quiet->status = 'canceled';
    $quiet->save();
    $observer->updated($quiet);
    expect(AdhocCfgRecorder::$sent)->toHaveCount(2);

    // An order without a config of its own runs no triggers.
    $plain         = adhocCfgOrder($connection, ['uuid' => 'order-3', 'public_id' => 'order_three', 'status' => 'started', 'order_config_uuid' => null, 'customer_uuid' => 'contact-sam', 'customer_type' => 'Fleetbase\\FleetOps\\Models\\Contact']);
    $plain->status = 'arrived';
    $plain->save();
    $observer->updated($plain);

    // A push that fails is logged and doesn't break the update.
    AdhocCfgRecorder::$notified = ['throw'];
    $connection->table('orders')->where('uuid', 'order-1')->update(['status' => 'arrived']);
    $order                      = adhocCfgFresh();
    $order->status              = 'in_progress';
    $order->save();
    $observer->updated($order);

    expect(AdhocCfgRecorder::$sent)->toHaveCount(2)
        ->and(app('log')->warnings)->toContain('An order config action failed');
});

test('the customer is told once when the driver is about to reach the pickup, and only while the trigger\'s conditions pass', function () {
    $connection = adhocCfgBoot();
    $passenger  = OrderConfigTemplates::attributes('passenger');
    adhocCfgTriggers($connection, [
        ...$passenger['triggers'],
        ['event' => OrderConfigTriggers::DRIVER_APPROACHING, 'options' => ['target' => 'dropoff', 'distance' => 300], 'action' => 'set_meta', 'params' => ['key' => 'near_dropoff', 'value' => 'yes']],
    ]);
    adhocCfgSeedOrder($connection, ['status' => 'arrived', 'driver_assigned_uuid' => 'ben']);
    $connection->table('drivers')->where('uuid', 'far')->update(['location' => adhocCfgWkb(103.8452, 1.3010)]);
    $connection->table('users')->insert(['uuid' => 'user-ben', 'company_uuid' => 'company-1', 'name' => 'Ben Tan', 'type' => 'driver']);
    $connection->table('drivers')->where('uuid', 'ben')->update(['user_uuid' => 'user-ben']);
    $near    = adhocCfgDriver('ben');
    $far     = adhocCfgDriver('chen');
    $nowhere = new Driver();

    // Arrived already: the pickup trigger's `when` (status is started) doesn't pass, and isn't used up.
    $arrived = adhocCfgFresh();
    expect(OrderConfigTriggers::driverMoved($near, null))->toBe(0)
        ->and(OrderConfigTriggers::driverMoved($near, $arrived))->toBe(0);

    $connection->table('orders')->update(['status' => 'started']);
    $started = adhocCfgFresh();
    expect(OrderConfigTriggers::driverMoved($far, $started))->toBe(0)
        ->and(OrderConfigTriggers::driverMoved($nowhere, $started))->toBe(0)
        ->and(OrderConfigTriggers::driverMoved($near, $started))->toBe(1)
        ->and(OrderConfigTriggers::driverMoved($near, adhocCfgFresh()))->toBe(0)
        ->and(AdhocCfgRecorder::$notified)->toBe([['contact-sam', OrderUpdate::class]])
        ->and([AdhocCfgRecorder::$sent[0]->title, AdhocCfgRecorder::$sent[0]->message, AdhocCfgRecorder::$sent[0]->data['type']])->toBe(['Your driver is almost there', 'Ben is about a minute away.', 'order_driver_approaching']);

    // Each target is its own once: coming close to the drop-off fires that trigger, once.
    $atDropoff = adhocCfgDriver('far');
    expect(OrderConfigTriggers::driverMoved($atDropoff, adhocCfgFresh()))->toBe(1)
        ->and(OrderConfigTriggers::driverMoved($atDropoff, adhocCfgFresh()))->toBe(0)
        ->and(adhocCfgFresh()->getMeta('near_dropoff'))->toBe('yes');

    // An order without a config of its own has no triggers.
    $plain = adhocCfgOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_two', 'status' => 'started', 'order_config_uuid' => null]);
    expect(OrderConfigTriggers::driverMoved($near, $plain))->toBe(0);
});

test('the queued action job reads the order again and runs a registered action', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgOrder($connection);

    $ran     = (new RunOrderConfigAction($order, 'set_meta', ['key' => 'flag', 'value' => 'on']))->handle();
    $unknown = (new RunOrderConfigAction($order, 'send_invoice'))->handle();
    $connection->table('orders')->delete();
    $gone = (new RunOrderConfigAction($order, 'set_meta', ['key' => 'flag', 'value' => 'off']))->handle();

    expect($ran)->toBeTrue()
        ->and($unknown)->toBeFalse()
        ->and($gone)->toBeFalse()
        ->and((new RunOrderConfigAction($order, 'set_meta'))->orderUuid)->toBe('order-1');
});

/** Positions the driver reported: one before metering began, then 1.0 km and 1.05 km. */
function adhocCfgSeedPositions(SQLiteConnection $connection): void
{
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-board', 'tracking_number_uuid' => 'tn-1', 'code' => 'IN_PROGRESS', 'created_at' => '2026-11-06 09:05:00']);
    $connection->table('positions')->insert([
        ['uuid' => 'p0', 'order_uuid' => 'order-1', 'coordinates' => adhocCfgWkb(103.8511, 1.2700), 'created_at' => '2026-11-06 09:04:00'],
        ['uuid' => 'p1', 'order_uuid' => 'order-1', 'coordinates' => adhocCfgWkb(103.8511, 1.2841), 'created_at' => '2026-11-06 09:05:10'],
        ['uuid' => 'p2', 'order_uuid' => 'order-1', 'coordinates' => adhocCfgWkb(103.8511, 1.2931), 'created_at' => '2026-11-06 09:09:00'],
        ['uuid' => 'p3', 'order_uuid' => 'order-1', 'coordinates' => adhocCfgWkb(103.8452, 1.3005), 'created_at' => '2026-11-06 09:14:00'],
    ]);
}

test('a completed order is priced again from the distance its driver covered and the time since metering began', function () {
    $connection = adhocCfgBoot();
    adhocCfgPolicies($connection, ['pricing' => ['reprice_on_completion' => true, 'metering_starts_at' => 'in_progress']]);
    $order = adhocCfgSeedOrder($connection);
    adhocCfgSeedPositions($connection);

    Carbon::setTestNow('2026-11-06 09:15:00');
    $quote = OrderRepricing::settle($order);
    Carbon::setTestNow();

    $purchaseUuid = $connection->table('orders')->where('uuid', 'order-1')->value('purchase_rate_uuid');
    $purchase     = Fleetbase\FleetOps\Models\PurchaseRate::where('uuid', $purchaseUuid)->first();
    $repriced     = data_get($purchase->meta, 'repriced');

    // About 1.0 km plus 1.05 km over 10 minutes: base 300, then 2.05 × 120 + 10 × 20 = 746 cents.
    expect($quote)->toBeInstanceOf(Fleetbase\FleetOps\Models\ServiceQuote::class)
        ->and((int) $quote->amount)->toBeGreaterThan(740)->toBeLessThan(750)
        ->and((int) $quote->distance)->toBeGreaterThan(2000)->toBeLessThan(2100)
        ->and((int) $quote->time)->toBe(600)
        ->and($quote->service_rate_uuid)->toBe('rate-economy')
        ->and($purchaseUuid)->not->toBe('purchase-booked')
        ->and($purchase->service_quote_uuid)->toBe($quote->uuid)
        ->and($repriced['distance'])->toBe((int) $quote->distance)
        ->and($repriced['time'])->toBe(600)
        ->and($repriced['distance_source'])->toBe('positions')
        ->and(Carbon::parse($repriced['metered_from'])->toDateTimeString())->toBe('2026-11-06 09:05:00')
        ->and($repriced['previous_amount'])->toEqual(533)
        ->and($connection->table('service_quote_items')->orderBy('id')->pluck('details')->all())->toBe(['Base', 'Distance and time']);

    // Priced once.
    expect(OrderRepricing::settle(adhocCfgFresh()))->toBeNull();
});

test('repricing waits for the pricing policy, a completed order with a rate, and a config of its own', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection);

    // The policy is off by default.
    expect(OrderRepricing::settle($order))->toBeNull()
        ->and($connection->table('service_quotes')->count())->toBe(1);

    adhocCfgPolicies($connection, ['pricing' => ['reprice_on_completion' => true]]);
    expect(OrderRepricing::settle(adhocCfgOrder($connection, ['uuid' => 'o-live', 'status' => 'started', 'purchase_rate_uuid' => 'purchase-booked'])))->toBeNull()
        ->and(OrderRepricing::settle(adhocCfgOrder($connection, ['uuid' => 'o-unpriced', 'status' => 'completed', 'purchase_rate_uuid' => null])))->toBeNull()
        ->and(OrderRepricing::settle(adhocCfgOrder($connection, ['uuid' => 'o-plain', 'status' => 'completed', 'order_config_uuid' => null, 'purchase_rate_uuid' => 'purchase-booked'])))->toBeNull()
        ->and($connection->table('service_quotes')->count())->toBe(1);
});

test('without positions the distance is the straight line, and metering starts at the activity, else when the order started', function () {
    $connection        = adhocCfgBoot();
    $order             = adhocCfgSeedOrder($connection);
    $order->started_at = null;

    [$metres, $source] = OrderRepricing::distance($order, Carbon::parse('2026-11-06 09:00:00'));
    $connection->table('payloads')->update(['dropoff_uuid' => null]);
    [$none, $nothing] = OrderRepricing::distance(adhocCfgFresh(), Carbon::now());

    $fromStart = OrderRepricing::meteringStartedAt(adhocCfgFresh(), 'in_progress');
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-board', 'tracking_number_uuid' => 'tn-1', 'code' => 'IN_PROGRESS', 'created_at' => '2026-11-06 09:05:00']);
    $fromActivity = OrderRepricing::meteringStartedAt(adhocCfgFresh(), 'in_progress');
    $noActivity   = OrderRepricing::meteringStartedAt(adhocCfgFresh());

    $label = new ReflectionMethod(OrderRepricing::class, 'label');
    $rate  = new Fleetbase\FleetOps\Models\ServiceRate();
    $rate->setRawAttributes(['rate_calculation_method' => 'per_meter'], true);
    $other = new Fleetbase\FleetOps\Models\ServiceRate();
    $other->setRawAttributes(['rate_calculation_method' => 'fixed_meter'], true);

    expect($source)->toBe('straight_line')
        ->and($metres)->toBeGreaterThan(1900)->toBeLessThan(2000)
        ->and([$none, $nothing])->toBe([0, 'none'])
        ->and($fromStart->toDateTimeString())->toBe('2026-11-06 09:00:00')
        ->and($fromActivity->toDateTimeString())->toBe('2026-11-06 09:05:00')
        ->and($noActivity->toDateTimeString())->toBe('2026-11-06 09:00:00')
        ->and([$label->invoke(null, $rate, 'Base Fee', 0), $label->invoke(null, $rate, 'Service Fee', 1), $label->invoke(null, $other, 'Peak', 2), $label->invoke(null, $other, '', 3)])->toBe(['Base', 'Distance', 'Peak', 'Charge']);
});

test('completing an order through the observer prices it again before its completed actions run', function () {
    $connection = adhocCfgBoot();
    $passenger  = OrderConfigTemplates::attributes('passenger');
    adhocCfgPolicies($connection, $passenger['policies']);
    $order = adhocCfgSeedOrder($connection, ['status' => 'in_progress']);

    $order->status = 'completed';
    $order->save();
    (new Fleetbase\FleetOps\Observers\OrderObserver())->updated($order);

    $purchase = Fleetbase\FleetOps\Models\PurchaseRate::where('uuid', $connection->table('orders')->value('purchase_rate_uuid'))->first();

    expect($purchase->uuid)->not->toBe('purchase-booked')
        ->and(data_get($purchase->meta, 'repriced.distance_source'))->toBe('straight_line')
        ->and(AdhocCfgRecorder::$notified)->toBe([['contact-sam', OrderUpdate::class]])
        ->and(AdhocCfgRecorder::$sent[0]->title)->toBe('You have arrived');
});

class AdhocCfgCustomerController extends Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController
{
    public ?string $customerUuid = 'contact-sam';
    public array $revoked        = [];
    public array $settings       = [];

    protected function currentCustomer(): ?Fleetbase\FleetOps\Models\Contact
    {
        return $this->customerUuid ? Fleetbase\FleetOps\Models\Contact::where('uuid', $this->customerUuid)->first() : null;
    }

    protected function findOrderOrFail(string $id): Order
    {
        return AdhocCfgOrder::where('public_id', $id)->firstOrFail();
    }

    protected function deleteUserTokens(Fleetbase\Models\User $user): void
    {
        $this->revoked[] = $user->uuid;
    }

    public function helper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }

    protected function trackingPageUrl(string $number, ?string $companyUuid): string
    {
        return 'https://console.test/~/track/' . $number . ($companyUuid === 'company-1' ? '' : '?org=other');
    }

    protected function lookupCompanySetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    protected function resolveOrderConfig(Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest $request, string $companyUuid): ?OrderConfig
    {
        return OrderConfig::where('uuid', 'config-adhoc')->first();
    }

    protected function resolveServiceQuote(Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest $request): mixed
    {
        return Fleetbase\FleetOps\Models\ServiceQuote::where('uuid', 'quote-booked')->first();
    }
}

function adhocCfgJson($response): array
{
    return $response->getData(true);
}

test('chat, rating and sharing are refused with 403 while the config\'s customer interactions are off', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedOrder($connection, ['status' => 'started']);
    $controller = new AdhocCfgCustomerController();
    $post       = fn (array $input) => Illuminate\Http\Request::create('/', 'POST', $input);

    expect($controller->orderMessages('order_one')->getStatusCode())->toBe(403)
        ->and($controller->sendOrderMessage('order_one', $post(['text' => 'Hi']))->getStatusCode())->toBe(403)
        ->and($controller->rateOrder('order_one', $post(['stars' => 5]))->getStatusCode())->toBe(403)
        ->and($controller->shareOrder('order_one')->getStatusCode())->toBe(403)
        ->and(adhocCfgJson($controller->shareOrder('order_one'))['error'])->toBe('Sharing tracking is not available for this order.')
        // Someone else's order, or none, is not found before the policy is read.
        ->and($controller->orderMessages('order_missing')->getStatusCode())->toBe(404);

    $controller->customerUuid = null;
    expect($controller->orderMessages('order_one')->getStatusCode())->toBe(404);

    // Each interaction is its own setting.
    adhocCfgPolicies($connection, ['customer' => ['interactions' => ['rating' => true]]]);
    $controller = new AdhocCfgCustomerController();
    expect($controller->orderMessages('order_one')->getStatusCode())->toBe(403)
        ->and($controller->rateOrder('order_one', $post(['stars' => 5]))->getStatusCode())->toBe(422);
});

test('the customer chats with the driver of their own order only, as themselves', function () {
    $connection = adhocCfgBoot();
    adhocCfgPolicies($connection, ['customer' => ['interactions' => ['chat' => true]]]);
    adhocCfgSeedOrder($connection, ['status' => 'started', 'driver_assigned_uuid' => null]);
    $controller = new AdhocCfgCustomerController();
    $send       = fn (string $text, string $id = 'order_one') => $controller->sendOrderMessage($id, Illuminate\Http\Request::create('/', 'POST', ['text' => $text]));

    expect($controller->orderMessages('order_missing')->getStatusCode())->toBe(404)
        ->and(adhocCfgJson($controller->orderMessages('order_one')))->toBe([])
        ->and($send('Hi', 'order_missing')->getStatusCode())->toBe(404)
        ->and($send('   ')->getStatusCode())->toBe(422)
        ->and($send(str_repeat('x', 2001))->getStatusCode())->toBe(422)
        ->and(adhocCfgJson($send('Hi'))['error'])->toBe('You can message the driver once one is assigned.');

    $connection->table('orders')->update(['driver_assigned_uuid' => 'chen']);
    $sent = $send('By the lobby');

    expect($sent->getStatusCode())->toBe(201)
        ->and(adhocCfgJson($sent))->toMatchArray(['from' => 'customer', 'text' => 'By the lobby'])
        ->and(array_column(adhocCfgJson($controller->orderMessages('order_one')), 'text'))->toBe(['By the lobby']);

    $connection->table('orders')->update(['status' => 'completed']);
    expect(adhocCfgJson($send('Thanks'))['error'])->toBe('This conversation has ended.');
});

test('the customer rates a completed order once, from 1 to 5 stars', function () {
    $connection = adhocCfgBoot();
    adhocCfgPolicies($connection, ['customer' => ['interactions' => ['rating' => true]]]);
    adhocCfgSeedOrder($connection, ['status' => 'in_progress']);
    $controller = new AdhocCfgCustomerController();
    $rate       = fn ($stars, string $id = 'order_one') => $controller->rateOrder($id, Illuminate\Http\Request::create('/', 'POST', ['stars' => $stars, 'comment' => 'Kind']));

    expect($rate(5, 'order_missing')->getStatusCode())->toBe(404)
        ->and($rate(0)->getStatusCode())->toBe(422)
        ->and($rate(2.5)->getStatusCode())->toBe(422)
        ->and($rate('lots')->getStatusCode())->toBe(422)
        ->and(adhocCfgJson($rate(5))['error'])->toBe('Only a completed order can be rated.');

    $connection->table('orders')->update(['status' => 'completed']);
    expect(adhocCfgJson($rate(4)))->toBe(['status' => 'rated'])
        ->and($rate(1)->getStatusCode())->toBe(409);
});

test('the customer shares an order in progress, but not one that has ended or has no tracking number', function () {
    $connection = adhocCfgBoot();
    adhocCfgPolicies($connection, ['customer' => ['interactions' => ['share_tracking' => true]]]);
    adhocCfgSeedOrder($connection, ['status' => 'started']);
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    $controller = new AdhocCfgCustomerController();

    $shared = adhocCfgJson($controller->shareOrder('order_one'));
    expect($controller->shareOrder('order_missing')->getStatusCode())->toBe(404)
        ->and($shared['url'])->toContain('/~/track/TRK1')
        ->and($shared['url'])->toMatch('/[?&]share=\d+\.[a-f0-9]{64}$/')
        ->and($shared)->toHaveKey('expires_at');

    $connection->table('orders')->update(['status' => 'no_show']);
    expect($controller->shareOrder('order_one')->getStatusCode())->toBe(422);

    $connection->table('orders')->update(['status' => 'started', 'tracking_number_uuid' => null]);
    expect($controller->shareOrder('order_one')->getStatusCode())->toBe(422);
});

test('customers may cancel until the config\'s cancellation policy says, and order configs the company enabled for them', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedOrder($connection, ['status' => 'started']);
    $controller = new AdhocCfgCustomerController();
    $config     = fn () => OrderConfig::where('uuid', 'config-adhoc')->first();
    $mayCancel  = fn () => $controller->helper('customerMayCancel', adhocCfgFresh());

    // By default customers cancel any time before the order finishes.
    $before = $mayCancel();
    $connection->table('orders')->update(['status' => 'in_progress']);
    $inProgress = $mayCancel();
    $connection->table('orders')->update(['status' => 'no_show']);
    $ended = $mayCancel();

    adhocCfgPolicies($connection, ['customer' => ['cancellation' => ['allowed_until' => 'in_progress']]]);
    $connection->table('orders')->update(['status' => 'arrived']);
    $arrived = $mayCancel();
    $connection->table('orders')->update(['status' => 'in_progress']);
    $limited = $mayCancel();

    adhocCfgPolicies($connection, ['customer' => ['cancellation' => ['enabled' => false]]]);
    $connection->table('orders')->update(['status' => 'started']);
    $disabled = $mayCancel();

    expect([$before, $inProgress, $ended, $arrived, $limited, $disabled])->toBe([true, true, false, true, false, false])
        ->and($controller->helper('customersMayOrder', $config()))->toBeTrue();

    $controller->settings = ['fleet-ops.customer-enabled-order-configs' => ['config-other']];
    $refused              = $controller->helper('customersMayOrder', $config());
    $controller->settings = ['fleet-ops.customer-enabled-order-configs' => ['order_config_passenger']];
    expect($refused)->toBeFalse()
        ->and($controller->helper('customersMayOrder', $config()))->toBeTrue();
});

test('a customer deletes their account, but not while an order is in progress by its config', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedOrder($connection, ['status' => 'started']);
    $connection->table('places')->insert(['uuid' => 'place-home', 'company_uuid' => 'company-1', 'name' => 'Home', 'owner_uuid' => 'contact-sam']);
    $controller = new AdhocCfgCustomerController();

    $refused = $controller->deleteMe();
    expect($refused->getStatusCode())->toBe(422)
        ->and(adhocCfgJson($refused)['error'])->toContain('You have orders in progress');

    // The config's terminal activity (no_show) ends the order, though it isn't completed or canceled.
    $connection->table('orders')->update(['status' => 'no_show']);
    expect(adhocCfgJson($controller->deleteMe()))->toBe(['status' => 'deleted'])
        ->and($controller->revoked)->toBe(['user-sam'])
        ->and($connection->table('contacts')->where('uuid', 'contact-sam')->first())->toMatchArray(['name' => 'Deleted customer', 'email' => null, 'phone' => null])
        ->and($connection->table('contacts')->where('uuid', 'contact-sam')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('places')->where('uuid', 'place-home')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('users')->where('uuid', 'user-sam')->first())->toMatchArray(['name' => 'Deleted customer', 'phone' => null, 'email' => 'deleted-user-sam@deleted.invalid'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('deleted_at'))->toBeNull();

    $controller->customerUuid = null;
    expect($controller->deleteMe()->getStatusCode())->toBe(401);
});

test('an order that was never dispatched does not hold up deleting the account', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedOrder($connection, ['status' => 'created', 'dispatched' => 0]);

    expect(adhocCfgJson((new AdhocCfgCustomerController())->deleteMe()))->toBe(['status' => 'deleted']);
});

test('a customer with another company keeps their user when one account is deleted', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedOrder($connection);
    $connection->table('contacts')->insert(['uuid' => 'contact-sam-2', 'company_uuid' => 'company-2', 'user_uuid' => 'user-sam', 'name' => 'Sam Lee', 'type' => 'customer']);
    $nobody = new AdhocCfgCustomerController();
    $connection->table('contacts')->insert(['uuid' => 'contact-guest', 'company_uuid' => 'company-1', 'name' => 'Guest', 'type' => 'customer']);
    $nobody->customerUuid = 'contact-guest';

    (new AdhocCfgCustomerController())->deleteMe();
    $nobody->deleteMe();

    expect($connection->table('users')->where('uuid', 'user-sam')->value('deleted_at'))->toBeNull()
        ->and($connection->table('users')->where('uuid', 'user-sam')->value('name'))->toBe('Sam Lee')
        ->and($nobody->revoked)->toBe([])
        ->and($connection->table('contacts')->where('uuid', 'contact-guest')->value('deleted_at'))->not->toBeNull();
});

test('an unsettled order paid by a method the ordering policy names blocks the next order with 402, whatever its type', function () {
    $connection = adhocCfgBoot();
    adhocCfgPolicies($connection, ['customer' => ['ordering' => ['payment_methods' => ['cash', 'card'], 'require_settlement' => ['card']]]]);
    adhocCfgSeedOrder($connection, ['transaction_uuid' => 'txn-1', 'type' => 'anything']);
    $connection->table('transactions')->insert(['uuid' => 'txn-1', 'company_uuid' => 'company-1', 'amount' => 746, 'currency' => 'USD', 'settlement_status' => 'pending']);
    $connection->table('payloads')->update(['payment_method' => 'card']);
    $controller = new AdhocCfgCustomerController();
    $sam        = Fleetbase\FleetOps\Models\Contact::where('uuid', 'contact-sam')->first();
    $unsettled  = fn () => $controller->helper('hasUnsettledOrder', $sam, OrderConfig::where('uuid', 'config-adhoc')->first());

    $blocked  = $unsettled();
    $response = $controller->createOrder(new Fleetbase\FleetOps\Http\Requests\CreateCustomerOrderRequest(['payment_method' => 'card']));

    $connection->table('transactions')->update(['settlement_status' => 'paid']);
    $paid = $unsettled();
    $connection->table('transactions')->update(['settlement_status' => 'pending', 'amount' => 0]);
    $free = $unsettled();
    $connection->table('transactions')->update(['amount' => 746]);
    $connection->table('payloads')->update(['payment_method' => 'cash']);
    $cash = $unsettled();
    $connection->table('payloads')->update(['payment_method' => 'card']);
    $connection->table('orders')->update(['status' => 'started']);
    $notCompleted = $unsettled();
    $connection->table('orders')->update(['status' => 'completed']);
    adhocCfgPolicies($connection, ['customer' => ['ordering' => ['require_settlement' => []]]]);
    $policyOff = $unsettled();

    expect([$blocked, $paid, $free, $cash, $notCompleted, $policyOff])->toBe([true, false, false, false, false, false])
        ->and($response->getStatusCode())->toBe(402)
        ->and(adhocCfgJson($response)['error'])->toBe('Settle your previous order before placing another.');
});

test('a driver listing nearby orders sees only the adhoc orders they could take', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $connection->table('orders')->insert([
        ['uuid' => 'o-held', 'public_id' => 'order_held', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => 'config-adhoc', 'adhoc_strategy' => null],
        ['uuid' => 'o-other', 'public_id' => 'order_other', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => 'config-adhoc', 'adhoc_strategy' => null],
        ['uuid' => 'o-lapsed', 'public_id' => 'order_lapsed', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => null, 'adhoc_strategy' => 'sequential'],
        ['uuid' => 'o-open', 'public_id' => 'order_open', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => null, 'adhoc_strategy' => 'broadcast'],
        ['uuid' => 'o-refused', 'public_id' => 'order_refused', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => null, 'adhoc_strategy' => 'broadcast'],
        ['uuid' => 'o-default', 'public_id' => 'order_default', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => null, 'adhoc_strategy' => null],
        ['uuid' => 'o-planned', 'public_id' => 'order_planned', 'company_uuid' => 'company-1', 'adhoc' => 0, 'order_config_uuid' => null, 'adhoc_strategy' => null],
    ]);
    $connection->table('orders')->insert(['uuid' => 'o-taken', 'public_id' => 'order_taken', 'company_uuid' => 'company-1', 'adhoc' => 1, 'driver_assigned_uuid' => 'chen']);
    $connection->table('order_offers')->insert([
        ['uuid' => 'f1', 'order_uuid' => 'o-held', 'driver_uuid' => 'amara', 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00'],
        ['uuid' => 'f2', 'order_uuid' => 'o-other', 'driver_uuid' => 'chen', 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00'],
        ['uuid' => 'f3', 'order_uuid' => 'o-lapsed', 'driver_uuid' => 'amara', 'status' => 'pending', 'expires_at' => '2020-01-01 00:00:00'],
        ['uuid' => 'f4', 'order_uuid' => 'o-refused', 'driver_uuid' => 'amara', 'status' => 'declined', 'expires_at' => null],
    ]);
    $company = Fleetbase\Models\Company::where('uuid', 'company-1')->first();
    $listed  = fn ($company) => Fleetbase\FleetOps\Support\AdhocDispatch\AdhocOfferListing::apply(Order::query()->withoutGlobalScopes(), 'amara', $company)->orderBy('id')->pluck('public_id')->all();

    // One at a time by default: Amara sees her own open offer, the open order she didn't decline,
    // planned orders, and an order someone already took.
    expect($listed($company))->toBe(['order_held', 'order_open', 'order_planned', 'order_taken']);

    // With the company defaulting to everyone at once, unset orders show too; orders whose config's
    // adhoc policy says one at a time still don't.
    $connection->table('companies')->where('uuid', 'company-1')->update(['options' => json_encode(['fleetops' => ['adhoc_strategy' => 'broadcast']])]);
    $company = Fleetbase\Models\Company::where('uuid', 'company-1')->first();
    expect($listed($company))->toBe(['order_held', 'order_open', 'order_default', 'order_planned', 'order_taken']);

    // Each order carries Amara's open offer, or none.
    $orders = Order::withoutGlobalScopes()->whereIn('uuid', ['o-held', 'o-open'])->orderBy('id')->get();
    Fleetbase\FleetOps\Support\AdhocDispatch\AdhocOfferListing::attach($orders, 'amara');
    expect($orders[0]->adhocOffer->uuid)->toBe('f1')
        ->and($orders[1]->adhocOffer)->toBeNull()
        ->and(Fleetbase\FleetOps\Support\AdhocDispatch\AdhocOfferListing::present($orders[0]->adhocOffer))->toBe(['status' => 'pending', 'expires_at' => '2099-01-01T00:00:00.000000Z'])
        ->and(Fleetbase\FleetOps\Support\AdhocDispatch\AdhocOfferListing::present(null))->toBeNull();
});

test('only a strategy that lists openly shows orders without an offer, and a driver is found by public id', function () {
    adhocCfgBoot();
    adhocCfgSeedDrivers(Illuminate\Support\Facades\DB::connection());
    $helper = new ReflectionMethod(Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController::class, 'nearbyDriverUuid');
    $ask    = fn ($nearby) => $helper->invoke(new Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController(), Illuminate\Http\Request::create('/', 'GET', ['nearby' => $nearby]));

    expect(AdhocDispatch::openlyListed())->toBe(['broadcast'])
        ->and((new BroadcastStrategy())->listsOpenly())->toBeTrue()
        ->and((new SequentialStrategy())->listsOpenly())->toBeFalse()
        ->and($ask('driver_amara'))->toBe('amara')
        ->and($ask('driver_rival'))->toBeNull()
        ->and($ask('1.28,103.85'))->toBeNull()
        ->and($ask(null))->toBeNull();
});

test('drivers hear when their offer closes, and a broadcast failure is only logged', function () {
    $connection = adhocCfgBoot();
    adhocCfgSeedDrivers($connection);
    $order                    = adhocCfgOrder($connection);
    AdhocCfgRecorder::$events = [];

    adhocCfgSequential()->dispatch($order);
    $offer = OrderOffers::pending($order);
    Carbon::setTestNow(now()->addMinute());
    adhocCfgSequential()->expire($offer);
    Carbon::setTestNow();
    OrderOffers::withdraw($order);
    $closed = array_values(array_filter(AdhocCfgRecorder::$events, fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderOfferClosed));

    expect(array_map(fn ($event) => $event->data, $closed))->toBe([['id' => 'order_one', 'status' => 'expired'], ['id' => 'order_one', 'status' => 'withdrawn']])
        ->and($closed[0]->broadcastAs())->toBe('order.offer_closed')
        ->and(array_map(fn ($channel) => $channel->name, $closed[0]->broadcastOn()))->toBe(['driver.driver_amara', 'driver.amara'])
        ->and($closed[1]->broadcastWith())->toMatchArray(['event' => 'order.offer_closed', 'data' => ['id' => 'order_one', 'status' => 'withdrawn']]);

    AdhocCfgRecorder::$failEvents = true;
    OrderOffers::announceClosed($order, ['chen'], 'withdrawn');
    AdhocCfgRecorder::$failEvents = false;
    expect(app('log')->warnings)->toContain('Unable to broadcast order.offer_closed');
});

test('saving an order config drops an unknown strategy from its policies and keeps its offer time in range', function () {
    $connection = adhocCfgBoot();
    $config     = OrderConfig::where('uuid', 'config-adhoc')->first();
    $stored     = fn () => json_decode($connection->table('order_configs')->value('policies'), true);

    $config->forceFill(['policies' => ['dispatch' => ['adhoc' => ['strategy' => 'lottery', 'offer_timeout' => 900]], 'custom' => ['kept' => true]]])->save();
    expect($stored())->toBe(['dispatch' => ['adhoc' => ['strategy' => null, 'offer_timeout' => 120]], 'custom' => ['kept' => true]]);

    $config->forceFill(['policies' => ['dispatch' => ['adhoc' => ['strategy' => 'broadcast', 'offer_timeout' => null]]]])->save();
    expect($stored())->toBe(['dispatch' => ['adhoc' => ['strategy' => 'broadcast', 'offer_timeout' => null]]])
        ->and(OrderConfig::where('uuid', 'config-adhoc')->first()->policy('dispatch.adhoc.strategy'))->toBe('broadcast');
});

test('strategies describe themselves for people choosing one', function () {
    expect(AdhocDispatch::describe())->toContain(
        ['name' => 'broadcast', 'label' => 'Everyone nearby at once', 'description' => (new BroadcastStrategy())->description()],
        ['name' => 'sequential', 'label' => 'One driver at a time', 'description' => (new SequentialStrategy())->description()],
    );
});

test('a customer order keeps how it will be paid on its payload, and tells how dispatch ended', function () {
    $connection = adhocCfgBoot();
    $order      = adhocCfgSeedOrder($connection, ['status' => 'canceled', 'driver_assigned_uuid' => null]);
    $connection->table('order_offers')->insert(['uuid' => 'f-x', 'order_uuid' => 'order-1', 'driver_uuid' => 'amara', 'status' => 'declined']);

    (new AdhocCfgCustomerController())->helper('setPayloadPaymentMethod', 'payload-1', 'card');

    expect($connection->table('payloads')->where('uuid', 'payload-1')->value('payment_method'))->toBe('card')
        ->and($order->dispatchOutcome())->toBe('no_driver_found')
        ->and($order->chatChannel)->toBeNull();
});
