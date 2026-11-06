<?php

use Fleetbase\FleetOps\Console\Commands\CreateRideConfig;
use Fleetbase\FleetOps\Jobs\ExpireOrderOffer;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Fleetbase\FleetOps\Support\AdhocDispatch\BroadcastStrategy;
use Fleetbase\FleetOps\Support\AdhocDispatch\SequentialStrategy;
use Fleetbase\FleetOps\Support\DriverMatching;
use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;

/**
 * Timed offers against SQLite: the shared driver filters (company, busy, vehicle class), offering
 * an order to the nearest driver, accepting, declining, expiring and withdrawing offers, giving up
 * when nobody is left, the expiry job, and the ride config and its command.
 */
if (!function_exists('Fleetbase\Observers\event')) {
    eval('namespace Fleetbase\Observers; function event($event = null, $payload = []) { return []; }');
}

if (!function_exists('Fleetbase\FleetOps\Support\event')) {
    eval('namespace Fleetbase\FleetOps\Support; function event($event = null) { if (\RideOffersRecorder::$failEvents) { throw new \RuntimeException(\'socket down\'); } \RideOffersRecorder::$events[] = $event; return $event; }');
}

class RideOffersRecorder
{
    public static array $notified  = [];
    public static array $events    = [];
    public static bool $failEvents = false;
}

/** An order whose cancellation is recorded rather than run, and whose pickup is set directly. */
class RideOffersOrder extends Order
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
function rideOffersWkb(float $longitude, float $latitude): string
{
    return pack('V', 0) . pack('C', 1) . pack('V', 1) . pack('e', $longitude) . pack('e', $latitude);
}

/** [longitude, latitude] of a stored point or of WKT text. */
function rideOffersPoint(?string $value): array
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

function rideOffersBoot(): SQLiteConnection
{
    if (!Illuminate\Support\Str::hasMacro('humanize')) {
        Illuminate\Support\Str::macro('humanize', fn ($value, $uppercase = true) => str_replace('_', ' ', Illuminate\Support\Str::snake((string) $value)));
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->sqliteCreateFunction('ST_GeomFromText', fn ($wkt, $srid = 0, $axis = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_PointFromText', fn ($wkt, $srid = 0, $axis = null) => $wkt);
    $pdo->sqliteCreateFunction('ST_X', fn ($wkt) => rideOffersPoint($wkt)[0]);
    $pdo->sqliteCreateFunction('ST_Y', fn ($wkt) => rideOffersPoint($wkt)[1]);
    $pdo->sqliteCreateFunction('st_distance_sphere', function ($a, $b) {
        [$lng1, $lat1] = rideOffersPoint($a);
        [$lng2, $lat2] = rideOffersPoint($b);
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

    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class {
        public function send($notifiables, $notification)
        {
            if (RideOffersRecorder::$notified === ['throw']) {
                throw new RuntimeException('push failed');
            }
            RideOffersRecorder::$notified[] = [$notifiables->uuid, $notification::class];
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
        'orders'              => ['uuid', 'public_id', 'company_uuid', 'adhoc_strategy', 'required_vehicle_type', 'chat_channel_uuid', 'fare_finalized_at', 'paid_at', 'tracking_number_uuid', 'dispatched_at', 'started_at', 'customer_uuid', 'customer_type', 'purchase_rate_uuid', 'transaction_uuid', 'order_config_uuid', 'payload_uuid', 'driver_assigned_uuid', 'status', 'type', 'adhoc', 'adhoc_distance', 'dispatched', 'started', 'meta'],
        'order_configs'       => ['uuid', 'public_id', 'company_uuid', 'adhoc_strategy', 'adhoc_offer_timeout', 'customer_orders_adhoc', 'match_vehicle_type', 'name', 'key', 'namespace', 'description', 'flow', 'entities', 'meta', 'version', 'core_service', 'status', 'type', 'tags'],
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
    $connection->table('order_configs')->insert([
        'uuid'                => 'config-ride',
        'company_uuid'        => 'company-1',
        'key'                 => 'ride',
        'namespace'           => 'system:order-config:ride',
        'flow'                => json_encode(FleetOps::rideConfigDefaults()['flow']),
        'adhoc_strategy'      => 'sequential',
        'adhoc_offer_timeout' => 15,
    ]);
    $connection->table('places')->insert(['uuid' => 'place-pickup', 'company_uuid' => 'company-1', 'name' => 'Raffles Place', 'location' => rideOffersWkb(103.8511, 1.2841)]);
    $connection->table('payloads')->insert(['uuid' => 'payload-1', 'company_uuid' => 'company-1', 'pickup_uuid' => 'place-pickup']);
    $connection->table('vehicles')->insert([
        ['uuid' => 'car-economy', 'company_uuid' => 'company-1', 'type' => 'Economy'],
        ['uuid' => 'car-xl', 'company_uuid' => 'company-1', 'type' => 'xl'],
    ]);

    RideOffersRecorder::$notified                       = [];
    Fleetbase\TestSupport\DispatchRecorder::$dispatched = [];
    RideOffersOrder::$canceled                          = [];
    RideOffersOrder::$pickupPoint                       = new Point(1.2841, 103.8511);

    return $connection;
}

/** Drivers around the pickup at Raffles Place, nearest first: Amara 545 m, Chen 1.2 km, Ben (XL) 210 m. */
function rideOffersSeedDrivers(SQLiteConnection $connection): void
{
    $connection->table('drivers')->insert([
        ['uuid' => 'amara', 'public_id' => 'driver_amara', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => rideOffersWkb(103.8511, 1.2890)],
        ['uuid' => 'chen', 'public_id' => 'driver_chen', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => rideOffersWkb(103.8511, 1.2950)],
        ['uuid' => 'ben', 'public_id' => 'driver_ben', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-xl', 'status' => 'available', 'online' => 1, 'location' => rideOffersWkb(103.8511, 1.2860)],
        ['uuid' => 'far', 'public_id' => 'driver_far', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => rideOffersWkb(103.9915, 1.3644)],
        ['uuid' => 'offline', 'public_id' => 'driver_offline', 'company_uuid' => 'company-1', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 0, 'location' => rideOffersWkb(103.8511, 1.2845)],
        ['uuid' => 'rival', 'public_id' => 'driver_rival', 'company_uuid' => 'company-2', 'vehicle_uuid' => 'car-economy', 'status' => 'available', 'online' => 1, 'location' => rideOffersWkb(103.8511, 1.2842)],
    ]);
}

function rideOffersOrder(SQLiteConnection $connection, array $attributes = []): RideOffersOrder
{
    $connection->table('orders')->insert(array_merge([
        'uuid'                  => 'order-1',
        'public_id'             => 'order_ride',
        'company_uuid'          => 'company-1',
        'order_config_uuid'     => 'config-ride',
        'payload_uuid'          => 'payload-1',
        'status'                => 'dispatched',
        'type'                  => 'ride',
        'adhoc'                 => 1,
        'adhoc_distance'        => 6000,
        'dispatched'            => 1,
        'started'               => 0,
        'required_vehicle_type' => 'economy',
    ], $attributes));

    return RideOffersOrder::where('uuid', $attributes['uuid'] ?? 'order-1')->first();
}

function rideOffersDriver(string $uuid): Driver
{
    return Driver::withoutGlobalScopes()->where('uuid', $uuid)->first();
}

function rideOffersStatuses(SQLiteConnection $connection): array
{
    return $connection->table('order_offers')->orderBy('id')->get(['driver_uuid', 'status'])->map(fn ($row) => [$row->driver_uuid, $row->status])->all();
}

test('drivers are matched by company, free from other jobs and of the vehicle type the order needs', function () {
    $connection = rideOffersBoot();
    $connection->table('users')->insert(['uuid' => 'user-moved', 'company_uuid' => 'company-1']);
    $connection->table('orders')->insert([
        ['uuid' => 'job-live', 'company_uuid' => 'company-1', 'status' => 'started', 'deleted_at' => null],
        ['uuid' => 'job-done', 'company_uuid' => 'company-1', 'status' => 'completed', 'deleted_at' => null],
        ['uuid' => 'job-gone', 'company_uuid' => 'company-1', 'status' => 'started', 'deleted_at' => now()],
    ]);
    $connection->table('drivers')->insert([
        ['uuid' => 'free', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'via-user', 'company_uuid' => 'company-9', 'user_uuid' => 'user-moved', 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'other-company', 'company_uuid' => 'company-2', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => null],
        ['uuid' => 'busy', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-live'],
        ['uuid' => 'finished', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-done'],
        ['uuid' => 'stale', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-economy', 'current_job_uuid' => 'job-gone'],
        ['uuid' => 'big-car', 'company_uuid' => 'company-1', 'user_uuid' => null, 'vehicle_uuid' => 'car-xl', 'current_job_uuid' => null],
    ]);

    $order = new Order();
    $order->setRawAttributes(['company_uuid' => 'company-1', 'required_vehicle_type' => ' ECONOMY '], true);
    $matched = DriverMatching::forOrder(Driver::query()->withoutGlobalScopes(), $order)->orderBy('id')->pluck('uuid')->all();

    $anyClass = new Order();
    $anyClass->setRawAttributes(['company_uuid' => 'company-1', 'required_vehicle_type' => '  '], true);
    $everyClass = DriverMatching::forOrder(Driver::query()->withoutGlobalScopes(), $anyClass)->orderBy('id')->pluck('uuid')->all();

    expect($matched)->toBe(['free', 'via-user', 'finished', 'stale'])
        ->and($everyClass)->toBe(['free', 'via-user', 'finished', 'stale', 'big-car'])
        ->and(DriverMatching::vehicleClass($anyClass))->toBeNull()
        ->and(DriverMatching::vehicleClass($order))->toBe('ECONOMY');
});

function sequential(): SequentialStrategy
{
    return new SequentialStrategy();
}

test('one at a time: the order goes to the nearest free driver of its type first', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);

    Carbon::setTestNow('2026-11-06 09:00:00');
    sequential()->dispatch($order);
    Carbon::setTestNow();
    $offer = OrderOffers::pending($order);

    expect(sequential()->name())->toBe('sequential')
        ->and($offer->driver_uuid)->toBe('amara')
        ->and($offer->distance)->toBeGreaterThan(500)->toBeLessThan(600)
        ->and($offer->expires_at->toDateTimeString())->toBe('2026-11-06 09:00:15')
        ->and(RideOffersRecorder::$notified)->toBe([['amara', OrderPing::class]])
        ->and(Fleetbase\TestSupport\DispatchRecorder::$dispatched)->toBe([['job' => ExpireOrderOffer::class, 'arguments' => [$offer->uuid]]])
        ->and($offer->order->uuid)->toBe('order-1')
        ->and($offer->driver->uuid)->toBe('amara');

    // Dispatching again keeps the open offer instead of offering it twice.
    sequential()->dispatch($order);
    expect(rideOffersStatuses($connection))->toBe([['amara', 'pending']]);
});

test('one at a time: declining moves the order on, and only the offered driver may accept', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);
    sequential()->dispatch($order);

    expect(sequential()->accept($order, rideOffersDriver('chen')))->toBeFalse()
        ->and(sequential()->decline($order, rideOffersDriver('chen')))->toBeFalse()
        ->and(sequential()->decline($order, rideOffersDriver('amara')))->toBeTrue()
        ->and(OrderOffers::pending($order)->driver_uuid)->toBe('chen')
        ->and(sequential()->accept($order, rideOffersDriver('amara')))->toBeFalse()
        ->and(sequential()->accept($order, rideOffersDriver('chen')))->toBeTrue()
        ->and(rideOffersStatuses($connection))->toBe([['amara', 'declined'], ['chen', 'accepted']]);
});

test('one at a time: an offer that ran out cannot be accepted and moves on once', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);
    sequential()->dispatch($order);
    $offer = OrderOffers::pending($order);

    expect(sequential()->expire($offer))->toBeFalse();

    Carbon::setTestNow(now()->addSeconds(30));
    $acceptedLate = sequential()->accept($order, rideOffersDriver('amara'));
    $first        = (new ExpireOrderOffer($offer->uuid))->handle();
    $again        = (new ExpireOrderOffer($offer->uuid))->handle();
    $missing      = (new ExpireOrderOffer('no-such-offer'))->handle();
    Carbon::setTestNow();

    expect($acceptedLate)->toBeFalse()
        ->and($first)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($missing)->toBeFalse()
        ->and(rideOffersStatuses($connection))->toBe([['amara', 'expired'], ['chen', 'pending']]);
});

test('one at a time: the scheduled check starts lost offers and moves stuck ones on', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);

    expect(sequential()->redispatch($order))->toBe(1)
        ->and(sequential()->redispatch($order))->toBe(0);

    Carbon::setTestNow(now()->addMinute());
    expect(sequential()->redispatch($order))->toBe(1);
    Carbon::setTestNow();

    $connection->table('order_offers')->update(['status' => 'declined']);
    expect(sequential()->redispatch($order))->toBe(0)
        ->and(rideOffersStatuses($connection))->toBe([['amara', 'declined'], ['chen', 'declined']]);
});

test('one at a time: when nobody is left the order is canceled as no driver found', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);
    sequential()->dispatch($order);
    sequential()->decline($order, rideOffersDriver('amara'));
    sequential()->decline($order, rideOffersDriver('chen'));

    // The cancel itself is recorded by the test order; mark it canceled as the real one would.
    $connection->table('orders')->where('uuid', 'order-1')->update(['status' => 'canceled']);
    $canceled = RideOffersOrder::where('uuid', 'order-1')->first();

    expect(RideOffersOrder::$canceled)->toBe([['order-1', OrderOffers::NO_DRIVER_FOUND]])
        ->and(OrderOffers::outcome($canceled))->toBe(OrderOffers::NO_DRIVER_FOUND_OUTCOME)
        ->and(OrderOffers::pending($order))->toBeNull();

    // Canceled while an offer was open, it wasn't for want of a driver.
    $connection->table('order_offers')->where('driver_uuid', 'chen')->update(['status' => 'withdrawn']);
    $notAdhoc = new Order();
    $notAdhoc->setRawAttributes(['uuid' => 'order-1', 'status' => 'canceled', 'adhoc' => 0], true);
    expect(OrderOffers::outcome(RideOffersOrder::where('uuid', 'order-1')->first()))->toBeNull()
        ->and(OrderOffers::outcome($notAdhoc))->toBeNull()
        ->and(OrderOffers::outcome($order))->toBeNull();
});

test('one at a time: an order without a usable pickup finds no driver', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    RideOffersOrder::$pickupPoint = null;

    sequential()->dispatch(rideOffersOrder($connection));

    expect(RideOffersOrder::$canceled)->toHaveCount(1);
});

test('one at a time: started, assigned or finished orders are not offered again', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);

    $started  = rideOffersOrder($connection, ['uuid' => 'o-started', 'started' => 1]);
    $assigned = rideOffersOrder($connection, ['uuid' => 'o-assigned', 'driver_assigned_uuid' => 'amara']);
    $canceled = rideOffersOrder($connection, ['uuid' => 'o-canceled', 'status' => 'canceled']);

    expect(sequential()->offerNext($started))->toBeNull()
        ->and(sequential()->offerNext($assigned))->toBeNull()
        ->and(sequential()->offerNext($canceled))->toBeNull()
        ->and(rideOffersStatuses($connection))->toBe([])
        ->and(RideOffersOrder::$canceled)->toBe([]);
});

test('one at a time: a failed push still leaves the offer open', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order                        = rideOffersOrder($connection);
    RideOffersRecorder::$notified = ['throw'];

    sequential()->dispatch($order);

    expect(OrderOffers::pending($order)->driver_uuid)->toBe('amara')
        ->and(app('log')->warnings)->toContain('Unable to send an order offer');
});

test('at once: every nearby driver of the type is offered the order, and the first to accept gets it', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order     = rideOffersOrder($connection, ['adhoc_strategy' => 'broadcast']);
    $broadcast = new BroadcastStrategy();

    $broadcast->dispatch($order);

    expect($broadcast->name())->toBe('broadcast')
        ->and(rideOffersStatuses($connection))->toBe([['amara', 'pending'], ['chen', 'pending']])
        ->and(RideOffersRecorder::$notified)->toBe([['amara', OrderPing::class], ['chen', OrderPing::class]])
        ->and($broadcast->accept($order, rideOffersDriver('ben')))->toBeTrue();

    OrderOffers::claimed($order, rideOffersDriver('chen'));
    expect(rideOffersStatuses($connection))->toBe([['amara', 'withdrawn'], ['chen', 'accepted']]);
});

test('at once: a driver who declines is not offered the order again', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order     = rideOffersOrder($connection, ['adhoc_strategy' => 'broadcast', 'required_vehicle_type' => null]);
    $broadcast = new BroadcastStrategy();
    $broadcast->dispatch($order);

    // Ben declines his offer; Far, never offered, declines from the list of nearby orders.
    expect($broadcast->decline($order, rideOffersDriver('ben')))->toBeTrue()
        ->and($broadcast->decline($order, rideOffersDriver('far')))->toBeTrue()
        ->and($broadcast->decline($order, rideOffersDriver('far')))->toBeTrue();

    Carbon::setTestNow('2026-11-06 10:00:00');
    $offered = $broadcast->redispatch($order);
    Carbon::setTestNow();

    expect($offered)->toBe(2)
        ->and(OrderOffers::declinedDrivers($order))->toBe(['ben', 'far'])
        ->and($connection->table('order_offers')->where('status', 'pending')->orderBy('id')->pluck('driver_uuid')->all())->toBe(['amara', 'chen'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('dispatched_at'))->toBe('2026-11-06 10:00:00');
});

test('at once: no pickup means nobody to offer, and a failed push skips only that driver', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $broadcast = new BroadcastStrategy();

    RideOffersOrder::$pickupPoint = null;
    expect($broadcast->redispatch(rideOffersOrder($connection, ['uuid' => 'o-nowhere'])))->toBe(0);

    RideOffersOrder::$pickupPoint = new Point(1.2841, 103.8511);
    RideOffersRecorder::$notified = ['throw'];
    expect($broadcast->redispatch(rideOffersOrder($connection)))->toBe(2);
});

test('which strategy an order uses: its own, its config\'s, its company\'s, then one at a time', function () {
    $connection = rideOffersBoot();
    $order      = rideOffersOrder($connection, ['adhoc_strategy' => 'broadcast']);
    $fromConfig = rideOffersOrder($connection, ['uuid' => 'o-config']);
    $configName = AdhocDispatch::nameFor($fromConfig);
    $configWait = AdhocDispatch::offerTimeout($fromConfig);
    $connection->table('order_configs')->update(['adhoc_strategy' => 'nonsense', 'adhoc_offer_timeout' => null]);
    $connection->table('companies')->where('uuid', 'company-1')->update(['options' => json_encode(['fleetops' => ['adhoc_strategy' => 'broadcast', 'adhoc_offer_timeout' => 500]])]);
    $fromCompany = RideOffersOrder::where('uuid', 'o-config')->first()->load('company');
    $connection->table('companies')->update(['options' => null]);
    $fallback = RideOffersOrder::where('uuid', 'o-config')->first();

    expect(AdhocDispatch::nameFor($order))->toBe('broadcast')
        ->and(AdhocDispatch::for($order))->toBeInstanceOf(BroadcastStrategy::class)
        ->and($configName)->toBe('sequential')
        ->and($configWait)->toBe(15)
        ->and(AdhocDispatch::nameFor($fromCompany))->toBe('broadcast')
        ->and(AdhocDispatch::offerTimeout($fromCompany))->toBe(120)
        ->and(AdhocDispatch::nameFor($fallback))->toBe(AdhocDispatch::DEFAULT)
        ->and(AdhocDispatch::offerTimeout($fallback))->toBe(20)
        ->and(AdhocDispatch::DEFAULT)->toBe('sequential');

    $connection->table('order_configs')->update(['adhoc_offer_timeout' => 2]);
    expect(AdhocDispatch::offerTimeout(RideOffersOrder::where('uuid', 'o-config')->first()))->toBe(10);
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
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order = rideOffersOrder($connection);
    $amara = rideOffersDriver('amara');

    $first = OrderOffers::record($order, $amara);
    $again = OrderOffers::record($order, $amara);
    OrderOffers::recordDecline($order, rideOffersDriver('ben'));

    expect($again->uuid)->toBe($first->uuid)
        ->and($first->expires_at)->toBeNull()
        ->and(OrderOffers::offeredDrivers($order))->toBe(['amara', 'ben'])
        ->and(OrderOffers::acceptPending($order, $amara))->toBeTrue()
        ->and(OrderOffers::withdraw($order))->toBe(0);
});

test('the ride config is a strict flow with a no-show ending, offered one driver at a time', function () {
    $defaults = FleetOps::rideConfigDefaults();
    $config   = new OrderConfig($defaults);

    expect($defaults['key'])->toBe('ride')
        ->and(array_keys($defaults['flow']))->toBe(['created', 'dispatched', 'started', 'arrived', 'in_progress', 'completed', 'no_show'])
        ->and($config->adhoc_strategy)->toBe('sequential')
        ->and($config->customer_orders_adhoc)->toBeTrue()
        ->and($config->match_vehicle_type)->toBeTrue()
        ->and($config->hasStrictTransitions())->toBeTrue()
        ->and($config->canTransition('arrived', 'no_show'))->toBeTrue()
        ->and($config->canTransition('arrived', 'completed'))->toBeFalse()
        ->and($config->canTransition('in_progress', 'completed'))->toBeTrue()
        ->and($config->isTerminalActivityCode('no_show'))->toBeTrue()
        ->and($config->getStartedActivity()->get('status'))->toBe('Driver on the way');
});

test('the create ride config command makes the config once per company', function () {
    $connection = rideOffersBoot();
    $connection->table('order_configs')->delete();

    $command = new class extends CreateRideConfig {
        public array $lines = [];
        public string $id   = '';
        public array $opts  = [];

        public function option($key = null)
        {
            return $this->opts[$key] ?? null;
        }

        public function argument($key = null)
        {
            return $this->id;
        }

        public function info($string, $verbosity = null)
        {
            $this->lines[] = $string;
        }

        public function error($string, $verbosity = null)
        {
            $this->lines[] = $string;
        }
    };

    $command->id   = 'company_missing';
    $missing       = $command->handle();
    $command->id   = 'company_one';
    $made          = $command->handle();
    $command->id   = 'company-1';
    $command->opts = ['emergency-number' => '995', 'support-phone' => ' ', 'support-email' => 'help@cabs.test'];
    $again         = $command->handle();

    expect($missing)->toBe(1)
        ->and($made)->toBe(0)
        ->and($again)->toBe(0)
        ->and($command->lines[0])->toBe('No company found for company_missing.')
        ->and($command->lines[1])->toContain('is ready for Acme Cabs.')
        ->and($connection->table('order_configs')->where('key', 'ride')->count())->toBe(1)
        ->and(json_decode($connection->table('order_configs')->where('key', 'ride')->value('meta'), true)['rider'])->toBe(['emergency_number' => '995', 'support_email' => 'help@cabs.test']);
});

test('a dispatched adhoc order is offered by its strategy, and a busy driver is left out', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    rideOffersOrder($connection, ['tracking_number_uuid' => 'tn-1']);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-1', 'tracking_number_uuid' => 'tn-1', 'code' => 'DISPATCHED']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'RIDE1', 'owner_uuid' => 'order-1']);
    $connection->table('orders')->insert(['uuid' => 'job-live', 'company_uuid' => 'company-1', 'status' => 'started']);
    $connection->table('drivers')->where('uuid', 'amara')->update(['current_job_uuid' => 'job-live']);

    (new Fleetbase\FleetOps\Listeners\HandleOrderDispatched())->handle(new Fleetbase\FleetOps\Events\OrderDispatched(RideOffersOrder::where('uuid', 'order-1')->first()));

    expect(rideOffersStatuses($connection))->toBe([['chen', 'pending']])
        ->and(RideOffersRecorder::$notified)->toBe([['chen', OrderPing::class]]);
});

test('the scheduled adhoc command asks each order\'s strategy', function () {
    $connection = rideOffersBoot();
    $lookup     = new ReflectionMethod(Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class, 'strategyFor');
    $command    = new Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders();

    expect($lookup->invoke($command, rideOffersOrder($connection, ['adhoc_strategy' => 'broadcast'])))->toBeInstanceOf(BroadcastStrategy::class)
        ->and($lookup->invoke($command, rideOffersOrder($connection, ['uuid' => 'o-2'])))->toBeInstanceOf(SequentialStrategy::class);
});

test('fares carry each class\'s seats and how soon its nearest free car could arrive', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
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

    expect($economy->availability)->toBe(['seats' => 5, 'pickup_eta' => 138])
        ->and($limo->availability)->toBe(['seats' => null, 'pickup_eta' => null])
        ->and($controller->helper('availabilityPoint', $asked, ['latitude' => 1.28, 'longitude' => 103.85]))->toBeInstanceOf(Point::class)
        ->and($controller->helper('availabilityPoint', $asked, 'place_pickup'))->toBeNull()
        ->and($controller->helper('availabilityPoint', $notAsked, ['latitude' => 1.28, 'longitude' => 103.85]))->toBeNull();

    // Without a pickup, quotes are returned as they are.
    $plain = $quote('economy');
    $controller->from(null);
    $controller->helper('serviceQuoteResourceCollection', [$plain]);
    expect($plain->availability)->toBeNull();
});

/** A finished ride by rider Sam with driver Chen, from Raffles Place to Bugis. */
function ridesSeedTrip(SQLiteConnection $connection, array $order = []): RideOffersOrder
{
    rideOffersSeedDrivers($connection);
    $connection->table('users')->insert([
        ['uuid' => 'user-sam', 'public_id' => 'user_sam', 'company_uuid' => 'company-1', 'name' => 'Sam Rider', 'type' => 'customer', 'email' => 'sam@rider.test', 'phone' => '+15550190301'],
        ['uuid' => 'user-chen', 'public_id' => 'user_chen', 'company_uuid' => 'company-1', 'name' => 'Chen Test', 'type' => 'driver', 'email' => 'chen@cabs.test', 'phone' => '+15550190203'],
    ]);
    $connection->table('drivers')->where('uuid', 'chen')->update(['user_uuid' => 'user-chen']);
    $connection->table('contacts')->insert(['uuid' => 'contact-sam', 'public_id' => 'contact_sam', 'company_uuid' => 'company-1', 'user_uuid' => 'user-sam', 'name' => 'Sam Rider', 'email' => 'sam@rider.test', 'phone' => '+15550190301', 'type' => 'customer']);
    $connection->table('places')->insert(['uuid' => 'place-dropoff', 'company_uuid' => 'company-1', 'name' => 'Bugis', 'location' => rideOffersWkb(103.8452, 1.3005)]);
    $connection->table('payloads')->where('uuid', 'payload-1')->update(['dropoff_uuid' => 'place-dropoff']);
    $connection->table('service_rates')->insert(['uuid' => 'rate-economy', 'company_uuid' => 'company-1', 'service_name' => 'Economy', 'service_type' => 'economy', 'base_fee' => 300, 'rate_calculation_method' => 'algo', 'algorithm' => 'max(200, {distance_km} * 120 + {time_min} * 20)', 'currency' => 'USD']);
    $connection->table('service_quotes')->insert(['uuid' => 'quote-booked', 'company_uuid' => 'company-1', 'service_rate_uuid' => 'rate-economy', 'amount' => 533, 'currency' => 'USD']);
    $connection->table('tracking_numbers')->insert(['uuid' => 'tn-1', 'company_uuid' => 'company-1', 'tracking_number' => 'RIDE1', 'owner_uuid' => 'order-1']);
    $connection->table('purchase_rates')->insert(['uuid' => 'purchase-booked', 'company_uuid' => 'company-1', 'service_quote_uuid' => 'quote-booked', 'payload_uuid' => 'payload-1', 'status' => 'created']);

    return rideOffersOrder($connection, array_merge([
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

test('the ride chat opens once a driver accepts, with just the rider and driver in it', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection, ['status' => 'started', 'driver_assigned_uuid' => null]);

    expect(Fleetbase\FleetOps\Support\Rides\RideChat::channel($order))->toBeNull();

    $connection->table('orders')->update(['driver_assigned_uuid' => 'chen']);
    $order   = RideOffersOrder::where('uuid', 'order-1')->first();
    $channel = Fleetbase\FleetOps\Support\Rides\RideChat::channel($order);
    $again   = Fleetbase\FleetOps\Support\Rides\RideChat::channel(RideOffersOrder::where('uuid', 'order-1')->first());

    expect($channel->meta)->toBe(['order_public_id' => 'order_ride', 'kind' => 'group'])
        ->and($again->uuid)->toBe($channel->uuid)
        ->and($connection->table('chat_channels')->count())->toBe(1)
        ->and($connection->table('chat_participants')->orderBy('id')->pluck('user_uuid')->all())->toBe(['user-sam', 'user-chen'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('chat_channel_uuid'))->toBe($channel->uuid);

    $sent = Fleetbase\FleetOps\Support\Rides\RideChat::send($channel, 'user-sam', 'By the lobby');
    $chen = $connection->table('chat_participants')->where('user_uuid', 'user-chen')->value('uuid');
    $connection->table('chat_messages')->insert(['uuid' => 'm-2', 'company_uuid' => 'company-1', 'chat_channel_uuid' => $channel->uuid, 'sender_uuid' => $chen, 'content' => 'Two minutes', 'created_at' => now()->addMinute()]);

    $messages = Fleetbase\FleetOps\Support\Rides\RideChat::messages($channel, 'user-sam');
    expect($messages->map(fn ($message) => [$message['from'], $message['text']])->all())->toBe([['rider', 'By the lobby'], ['driver', 'Two minutes']])
        ->and($messages[0]['id'])->toBe($sent->public_id ?? $sent->uuid);
});

test('a rider rates their driver once, and the driver keeps an average', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection);
    $connection->table('driver_ratings')->insert(['uuid' => 'r-old', 'order_uuid' => 'order-old', 'driver_uuid' => 'chen', 'stars' => 4]);

    $first  = Fleetbase\FleetOps\Support\Rides\DriverRatings::rate($order, 9, ' Great ');
    $second = Fleetbase\FleetOps\Support\Rides\DriverRatings::rate(RideOffersOrder::where('uuid', 'order-1')->first(), 1);
    $driver = rideOffersDriver('chen');
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
    Fleetbase\FleetOps\Support\Rides\DriverRatings::refresh('chen');
    expect([rideOffersDriver('chen')->rating_average, rideOffersDriver('chen')->rating_count])->toBe([null, 0]);
});

test('two ratings racing for one order keep the first', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection);
    // The other request's rating lands between the check and the insert; the unique index refuses this one.
    $connection->statement("CREATE TRIGGER rating_race BEFORE INSERT ON driver_ratings BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: driver_ratings.order_uuid'); END");

    expect(Fleetbase\FleetOps\Support\Rides\DriverRatings::rate($order, 5))->toBeFalse()
        ->and(rideOffersDriver('chen')->rating_count)->toBeNull();
});

test('a share link opens only its rider\'s view of its order, until it expires', function () {
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

test('ride pushes say what happened, by name, to the rider only', function () {
    $order = new Order();
    $order->setRawAttributes(['public_id' => 'order_ride'], true);
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'chen'], true);
    $driver->setRelation('user', null);
    $order->setRelation('driverAssigned', new class extends Driver {
        public function getNameAttribute()
        {
            return 'Chen Test';
        }
    });

    $arrived = new Fleetbase\FleetOps\Notifications\RideUpdate($order, 'arrived');
    $nobody  = new Fleetbase\FleetOps\Notifications\RideUpdate((new Order())->setRawAttributes(['public_id' => 'order_x'], true), 'started');

    expect($arrived->title)->toBe('Your driver has arrived')
        ->and($arrived->message)->toBe('Chen is waiting at the pickup point.')
        ->and($arrived->data)->toBe(['id' => 'order_ride', 'type' => 'ride_arrived'])
        ->and($arrived->via(null))->toBe([NotificationChannels\Fcm\FcmChannel::class, NotificationChannels\Apn\ApnChannel::class])
        ->and($nobody->message)->toBe('Your driver accepted your ride.')
        ->and(Fleetbase\FleetOps\Notifications\RideUpdate::knows('in_progress'))->toBeTrue()
        ->and(Fleetbase\FleetOps\Notifications\RideUpdate::knows('dispatched'))->toBeFalse();
});

test('each step of a ride reaches the rider, and the car about to arrive is told once', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection, ['status' => 'started']);
    $updates    = Fleetbase\FleetOps\Support\Rides\RideUpdates::class;
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));

    // Status changes
    $order->status = 'arrived';
    $order->save();
    $quiet   = RideOffersOrder::where('uuid', 'order-1')->first();
    $notRide = new Order();
    $notRide->setRawAttributes(['type' => 'transport'], true);
    expect($updates::statusChanged($order))->toBeTrue()
        ->and($updates::statusChanged($quiet))->toBeFalse()
        ->and($updates::statusChanged($notRide))->toBeFalse()
        ->and(RideOffersRecorder::$notified)->toBe([['contact-sam', Fleetbase\FleetOps\Notifications\RideUpdate::class]]);

    $connection->table('order_offers')->insert(['uuid' => 'f-out', 'order_uuid' => 'order-1', 'driver_uuid' => 'amara', 'status' => 'expired']);
    $order->status = 'canceled';
    $order->save();
    $updates::statusChanged($order);
    $order->status = 'dispatched';
    $order->save();
    expect($updates::statusChanged($order))->toBeFalse()
        ->and(RideOffersRecorder::$notified)->toHaveCount(2);

    // Arriving soon: only a started ride, only near the pickup, only once.
    $started         = RideOffersOrder::where('uuid', 'order-1')->first();
    $started->status = 'started';
    $near            = rideOffersDriver('ben');
    $far             = rideOffersDriver('chen');
    $nowhere         = new Driver();
    expect($updates::driverMoved($near, null))->toBeFalse()
        ->and($updates::driverMoved($near, $quiet->setRawAttributes(array_merge($quiet->getAttributes(), ['status' => 'arrived']))))->toBeFalse()
        ->and($updates::driverMoved($far, $started))->toBeFalse()
        ->and($updates::driverMoved($nowhere, $started))->toBeFalse()
        ->and($updates::driverMoved($near, $started))->toBeTrue()
        ->and($updates::driverMoved($near, $started))->toBeFalse();

    // A rider who can't be reached, and a push that fails, don't break anything.
    $orphan         = rideOffersOrder($connection, ['uuid' => 'order-2', 'public_id' => 'order_2', 'status' => 'started', 'customer_uuid' => null]);
    $orphan->status = 'arrived';
    $orphan->save();
    RideOffersRecorder::$notified = ['throw'];
    $order->status                = 'in_progress';
    $order->save();
    expect($updates::statusChanged($orphan))->toBeFalse()
        ->and($updates::statusChanged($order))->toBeFalse()
        ->and(app('log')->warnings)->toContain('Unable to send a ride update');
});

test('a finished ride is priced from the distance its car covered and the trip time', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection);
    $connection->table('tracking_statuses')->insert(['uuid' => 'ts-board', 'tracking_number_uuid' => 'tn-1', 'code' => 'IN_PROGRESS', 'created_at' => '2026-11-06 09:05:00']);
    $connection->table('positions')->insert([
        ['uuid' => 'p0', 'order_uuid' => 'order-1', 'coordinates' => rideOffersWkb(103.8511, 1.2700), 'created_at' => '2026-11-06 09:04:00'],
        ['uuid' => 'p1', 'order_uuid' => 'order-1', 'coordinates' => rideOffersWkb(103.8511, 1.2841), 'created_at' => '2026-11-06 09:05:10'],
        ['uuid' => 'p2', 'order_uuid' => 'order-1', 'coordinates' => rideOffersWkb(103.8511, 1.2931), 'created_at' => '2026-11-06 09:09:00'],
        ['uuid' => 'p3', 'order_uuid' => 'order-1', 'coordinates' => rideOffersWkb(103.8452, 1.3005), 'created_at' => '2026-11-06 09:14:00'],
    ]);

    Carbon::setTestNow('2026-11-06 09:15:00');
    $fare = Fleetbase\FleetOps\Support\Rides\FinalFare::settle($order);
    Carbon::setTestNow();

    // About 1.0 km plus 1.05 km over 10 minutes: base 300, then 2.05 × 120 + 10 × 20 = 746 cents.
    expect($fare['final'])->toBeTrue()
        ->and($fare['distance_source'])->toBe('positions')
        ->and($fare['distance_m'])->toBeGreaterThan(2000)->toBeLessThan(2100)
        ->and($fare['duration_s'])->toBe(600)
        ->and($fare['estimate'])->toBe(533)
        ->and($fare['total'])->toBeGreaterThan(740)->toBeLessThan(750)
        ->and(array_column($fare['lines'], 'label'))->toBe(['Base fare', 'Distance and time'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('fare_finalized_at'))->not->toBeNull()
        ->and(RideOffersOrder::where('uuid', 'order-1')->first()->fare())->toMatchArray(['final' => true, 'total' => $fare['total'], 'currency' => 'USD', 'estimate' => 533, 'distance' => $fare['distance_m'], 'time' => 600, 'service' => 'Economy'])
        ->and($connection->table('service_quotes')->where('uuid', '!=', 'quote-booked')->value('amount'))->toBe((string) $fare['total'])
        ->and($connection->table('service_quote_items')->count())->toBe(2)
        ->and($connection->table('orders')->value('purchase_rate_uuid'))->not->toBe('purchase-booked');

    // Priced once.
    expect(Fleetbase\FleetOps\Support\Rides\FinalFare::settle(RideOffersOrder::where('uuid', 'order-1')->first()))->toBeNull();
});

test('a ride without positions is priced on the straight line, and other orders are left alone', function () {
    $connection        = rideOffersBoot();
    $order             = ridesSeedTrip($connection);
    $order->started_at = null;

    [$metres, $source] = Fleetbase\FleetOps\Support\Rides\FinalFare::distance($order, Carbon::parse('2026-11-06 09:00:00'));
    $connection->table('payloads')->update(['dropoff_uuid' => null]);
    [$none, $nothing] = Fleetbase\FleetOps\Support\Rides\FinalFare::distance(RideOffersOrder::where('uuid', 'order-1')->first(), Carbon::now());

    $started = Fleetbase\FleetOps\Support\Rides\FinalFare::tripStartedAt(RideOffersOrder::where('uuid', 'order-1')->first());
    $unrated = ridesSeedOtherRate($connection);

    expect($source)->toBe('straight_line')
        ->and($metres)->toBeGreaterThan(1900)->toBeLessThan(2000)
        ->and([$none, $nothing])->toBe([0, 'none'])
        ->and($started->toDateTimeString())->toBe('2026-11-06 09:00:00')
        ->and(Fleetbase\FleetOps\Support\Rides\FinalFare::settle(rideOffersOrder($connection, ['uuid' => 'o-live', 'status' => 'started', 'meta' => json_encode([])])))->toBeNull()
        ->and(Fleetbase\FleetOps\Support\Rides\FinalFare::settle(rideOffersOrder($connection, ['uuid' => 'o-transport', 'type' => 'transport', 'status' => 'completed'])))->toBeNull()
        ->and(Fleetbase\FleetOps\Support\Rides\FinalFare::settle(rideOffersOrder($connection, ['uuid' => 'o-unpriced', 'status' => 'completed', 'purchase_rate_uuid' => null])))->toBeNull()
        ->and($unrated)->toBe(['Base fare', 'Distance', 'Peak']);
});

/** The line labels a finished ride gets on a per-km rate, and on a rate named some other way. */
function ridesSeedOtherRate(SQLiteConnection $connection): array
{
    $label = new ReflectionMethod(Fleetbase\FleetOps\Support\Rides\FinalFare::class, 'label');
    $rate  = new Fleetbase\FleetOps\Models\ServiceRate();
    $rate->setRawAttributes(['rate_calculation_method' => 'per_meter'], true);
    $other = new Fleetbase\FleetOps\Models\ServiceRate();
    $other->setRawAttributes(['rate_calculation_method' => 'fixed_meter'], true);

    return [$label->invoke(null, $rate, 'Base Fee', 0), $label->invoke(null, $rate, 'Service Fee', 1), $label->invoke(null, $other, 'Peak', 2)];
}

class RidesCustomerController extends Fleetbase\FleetOps\Http\Controllers\Api\v1\CustomerController
{
    public ?string $customerUuid = 'contact-sam';
    public array $revoked        = [];

    protected function currentCustomer(): ?Fleetbase\FleetOps\Models\Contact
    {
        return $this->customerUuid ? Fleetbase\FleetOps\Models\Contact::where('uuid', $this->customerUuid)->first() : null;
    }

    protected function findOrderOrFail(string $id): Order
    {
        return RideOffersOrder::where('public_id', $id)->firstOrFail();
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
}

function ridesJson($response): array
{
    return $response->getData(true);
}

test('the rider chats with the driver of their own ride only, as themselves', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection, ['status' => 'started', 'driver_assigned_uuid' => null]);
    $controller = new RidesCustomerController();
    $send       = fn (string $text, string $id = 'order_ride') => $controller->sendOrderMessage($id, Illuminate\Http\Request::create('/', 'POST', ['text' => $text]));

    expect($controller->orderMessages('order_missing')->getStatusCode())->toBe(404)
        ->and(ridesJson($controller->orderMessages('order_ride')))->toBe([])
        ->and($send('Hi', 'order_missing')->getStatusCode())->toBe(404)
        ->and($send('   ')->getStatusCode())->toBe(422)
        ->and($send(str_repeat('x', 2001))->getStatusCode())->toBe(422)
        ->and(ridesJson($send('Hi'))['error'])->toContain('once they accept');

    $connection->table('orders')->update(['driver_assigned_uuid' => 'chen']);
    $sent = $send('By the lobby');

    expect($sent->getStatusCode())->toBe(201)
        ->and(ridesJson($sent))->toMatchArray(['from' => 'rider', 'text' => 'By the lobby'])
        ->and(array_column(ridesJson($controller->orderMessages('order_ride')), 'text'))->toBe(['By the lobby']);

    $connection->table('orders')->update(['status' => 'completed']);
    expect(ridesJson($send('Thanks'))['error'])->toBe('This ride has ended.');
});

test('the rider rates a finished ride once, from 1 to 5 stars', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection, ['status' => 'in_progress']);
    $controller = new RidesCustomerController();
    $rate       = fn ($stars, string $id = 'order_ride') => $controller->rateOrder($id, Illuminate\Http\Request::create('/', 'POST', ['stars' => $stars, 'comment' => 'Kind']));

    expect($rate(5, 'order_missing')->getStatusCode())->toBe(404)
        ->and($rate(0)->getStatusCode())->toBe(422)
        ->and($rate(2.5)->getStatusCode())->toBe(422)
        ->and($rate('lots')->getStatusCode())->toBe(422)
        ->and(ridesJson($rate(5))['error'])->toBe('Only a finished ride can be rated.');

    $connection->table('orders')->update(['status' => 'completed']);
    expect(ridesJson($rate(4)))->toBe(['status' => 'rated'])
        ->and($rate(1)->getStatusCode())->toBe(409);
});

test('the rider shares a ride in progress, and reads the operator settings', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection, ['status' => 'started']);
    config()->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    config()->set('fleetbase.console.host', 'https://console.test');
    $controller = new RidesCustomerController();

    $shared = ridesJson($controller->shareOrder('order_ride'));
    expect($controller->shareOrder('order_missing')->getStatusCode())->toBe(404)
        ->and($shared['url'])->toContain('/~/track/RIDE1')
        ->and($shared['url'])->toMatch('/[?&]share=\d+\.[a-f0-9]{64}$/');

    $connection->table('orders')->update(['tracking_number_uuid' => null]);
    expect($controller->shareOrder('order_ride')->getStatusCode())->toBe(422)
        ->and(ridesJson($controller->rideSettings()))->toBe(['emergency_number' => null, 'support' => ['phone' => null, 'email' => null]]);

    $connection->table('order_configs')->update(['meta' => json_encode(['rider' => ['emergency_number' => '995', 'support_email' => 'help@cabs.test']])]);
    expect(ridesJson($controller->rideSettings()))->toBe(['emergency_number' => '995', 'support' => ['phone' => null, 'email' => 'help@cabs.test']]);
});

test('a rider deletes their account, but not in the middle of a ride', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection, ['status' => 'started']);
    $connection->table('places')->insert(['uuid' => 'place-home', 'company_uuid' => 'company-1', 'name' => 'Home', 'owner_uuid' => 'contact-sam']);
    $controller = new RidesCustomerController();

    expect(ridesJson($controller->deleteMe())['error'])->toContain('Finish or cancel your ride');

    $connection->table('orders')->update(['status' => 'completed']);
    expect(ridesJson($controller->deleteMe()))->toBe(['status' => 'deleted'])
        ->and($controller->revoked)->toBe(['user-sam'])
        ->and($connection->table('contacts')->where('uuid', 'contact-sam')->first())->toMatchArray(['name' => 'Deleted rider', 'email' => null, 'phone' => null])
        ->and($connection->table('contacts')->where('uuid', 'contact-sam')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('places')->where('uuid', 'place-home')->value('deleted_at'))->not->toBeNull()
        ->and($connection->table('users')->where('uuid', 'user-sam')->first())->toMatchArray(['name' => 'Deleted rider', 'phone' => null, 'email' => 'deleted-user-sam@deleted.invalid'])
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('deleted_at'))->toBeNull();

    $controller->customerUuid = null;
    expect($controller->deleteMe()->getStatusCode())->toBe(401);
});

test('a rider with another company keeps their user when one account is deleted', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection);
    $connection->table('contacts')->insert(['uuid' => 'contact-sam-2', 'company_uuid' => 'company-2', 'user_uuid' => 'user-sam', 'name' => 'Sam Rider', 'type' => 'customer']);
    $nobody = new RidesCustomerController();
    $connection->table('contacts')->insert(['uuid' => 'contact-guest', 'company_uuid' => 'company-1', 'name' => 'Guest', 'type' => 'customer']);
    $nobody->customerUuid = 'contact-guest';

    (new RidesCustomerController())->deleteMe();
    $nobody->deleteMe();

    expect($connection->table('users')->where('uuid', 'user-sam')->value('deleted_at'))->toBeNull()
        ->and($connection->table('users')->where('uuid', 'user-sam')->value('name'))->toBe('Sam Rider')
        ->and($nobody->revoked)->toBe([])
        ->and($connection->table('contacts')->where('uuid', 'contact-guest')->value('deleted_at'))->not->toBeNull();
});

test('an unpaid card trip blocks the next booking, and cash never does', function () {
    $connection = rideOffersBoot();
    ridesSeedTrip($connection, ['fare_finalized_at' => '2026-11-06 09:15:00']);
    $connection->table('payloads')->update(['payment_method' => 'card']);
    $controller = new RidesCustomerController();
    $sam        = Fleetbase\FleetOps\Models\Contact::where('uuid', 'contact-sam')->first();

    $unpaid = $controller->helper('hasUnpaidRide', $sam);
    $order  = RideOffersOrder::where('uuid', 'order-1')->first();
    $marked = [$order->markPaid(), $order->markPaid()];
    $paid   = $controller->helper('hasUnpaidRide', $sam);
    $connection->table('orders')->update(['paid_at' => null]);
    $connection->table('payloads')->update(['payment_method' => 'cash']);
    $cash = $controller->helper('hasUnpaidRide', $sam);

    expect([$unpaid, $paid, $cash])->toBe([true, false, false])
        ->and($marked)->toBe([true, false]);
});

test('when a ride is completed the rider is told and the ride is priced', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection, ['status' => 'in_progress']);
    Illuminate\Support\Facades\Cache::swap(new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));

    $order->status = 'completed';
    $order->save();
    (new Fleetbase\FleetOps\Observers\OrderObserver())->updated($order);

    expect($connection->table('orders')->value('fare_finalized_at'))->not->toBeNull()
        ->and(RideOffersRecorder::$notified)->toBe([['contact-sam', Fleetbase\FleetOps\Notifications\RideUpdate::class]]);
});

test('a driver listing nearby orders sees only the adhoc orders they could take', function () {
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $connection->table('orders')->insert([
        ['uuid' => 'o-held', 'public_id' => 'order_held', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => 'config-ride', 'adhoc_strategy' => null],
        ['uuid' => 'o-other', 'public_id' => 'order_other', 'company_uuid' => 'company-1', 'adhoc' => 1, 'order_config_uuid' => 'config-ride', 'adhoc_strategy' => null],
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

    // With the company defaulting to everyone at once, unset orders show too.
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
    rideOffersBoot();
    rideOffersSeedDrivers(Illuminate\Support\Facades\DB::connection());
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
    $connection = rideOffersBoot();
    rideOffersSeedDrivers($connection);
    $order                      = rideOffersOrder($connection);
    RideOffersRecorder::$events = [];

    sequential()->dispatch($order);
    $offer = OrderOffers::pending($order);
    Carbon::setTestNow(now()->addMinute());
    sequential()->expire($offer);
    Carbon::setTestNow();
    OrderOffers::withdraw($order);
    $closed = array_values(array_filter(RideOffersRecorder::$events, fn ($event) => $event instanceof Fleetbase\FleetOps\Events\OrderOfferClosed));

    expect(array_map(fn ($event) => $event->data, $closed))->toBe([['id' => 'order_ride', 'status' => 'expired'], ['id' => 'order_ride', 'status' => 'withdrawn']])
        ->and($closed[0]->broadcastAs())->toBe('order.offer_closed')
        ->and(array_map(fn ($channel) => $channel->name, $closed[0]->broadcastOn()))->toBe(['driver.driver_amara', 'driver.amara'])
        ->and($closed[1]->broadcastWith())->toMatchArray(['event' => 'order.offer_closed', 'data' => ['id' => 'order_ride', 'status' => 'withdrawn']]);

    RideOffersRecorder::$failEvents = true;
    OrderOffers::announceClosed($order, ['chen'], 'withdrawn');
    RideOffersRecorder::$failEvents = false;
    expect(app('log')->warnings)->toContain('Unable to broadcast order.offer_closed');
});

test('an order config drops an unknown strategy and keeps its offer time in range', function () {
    $connection = rideOffersBoot();
    $config     = OrderConfig::where('uuid', 'config-ride')->first();

    $config->forceFill(['adhoc_strategy' => 'lottery', 'adhoc_offer_timeout' => 900])->save();
    expect($connection->table('order_configs')->value('adhoc_strategy'))->toBeNull()
        ->and((int) $connection->table('order_configs')->value('adhoc_offer_timeout'))->toBe(120);

    $config->forceFill(['adhoc_strategy' => 'broadcast', 'adhoc_offer_timeout' => null])->save();
    expect($connection->table('order_configs')->value('adhoc_strategy'))->toBe('broadcast')
        ->and($connection->table('order_configs')->value('adhoc_offer_timeout'))->toBeNull();
});

test('strategies describe themselves for people choosing one', function () {
    expect(AdhocDispatch::describe())->toContain(
        ['name' => 'broadcast', 'label' => 'Everyone nearby at once', 'description' => (new BroadcastStrategy())->description()],
        ['name' => 'sequential', 'label' => 'One driver at a time', 'description' => (new SequentialStrategy())->description()],
    );
});

test('a rider order keeps how it will be paid on its payload, and tells how dispatch ended', function () {
    $connection = rideOffersBoot();
    $order      = ridesSeedTrip($connection, ['status' => 'canceled']);
    $connection->table('order_offers')->insert(['uuid' => 'f-x', 'order_uuid' => 'order-1', 'driver_uuid' => 'amara', 'status' => 'declined']);

    (new RidesCustomerController())->helper('setPayloadPaymentMethod', 'payload-1', 'card');

    expect($connection->table('payloads')->where('uuid', 'payload-1')->value('payment_method'))->toBe('card')
        ->and($order->dispatchOutcome())->toBe('no_driver_found')
        ->and($order->chatChannel)->toBeNull();
});
