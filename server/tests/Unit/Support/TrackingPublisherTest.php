<?php

use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Jobs\PublishTrackingUpdate;
use Fleetbase\FleetOps\Listeners\PublishTrackingUpdates;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingChannel;
use Fleetbase\FleetOps\Support\TrackingPublisher;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingIntelligenceService;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\User;
use Fleetbase\TestSupport\DispatchRecorder;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Covers publishing to public tracking channels: the request-path hooks (gated on socket
 * authentication, throttled for positions, queueing a job), the queued job, the stop listener,
 * and the per-customer publication of full and position-only updates.
 */
class TrackingPublisherProbe extends TrackingPublisher
{
    public array $sent   = [];
    public bool $accepts = true;

    protected function send(string $channel, array $payload): bool
    {
        $this->sent[] = [$channel, $payload];

        return $this->accepts;
    }

    public function realSend(string $channel, array $payload): bool
    {
        return parent::send($channel, $payload);
    }

    public function tracker(Order $order): array
    {
        return $this->trackerFor($order);
    }
}

class TrackingPublisherContextBuilderFake extends TrackingContextBuilder
{
    public function __construct(public Collection $stops)
    {
    }

    public function build(Order $order, TrackingOptions $options): TrackingContext
    {
        return new TrackingContext(
            order: $order,
            payload: null,
            driver: null,
            origin: null,
            driverLocation: null,
            stops: $this->stops,
            completedStops: collect(),
            remainingStops: collect(),
            activeStop: null,
            nextStop: null,
            driverLocationAgeSeconds: null,
        );
    }
}

class TrackingPublisherIntelligenceFake extends TrackingIntelligenceService
{
    public int $calls = 0;

    public function __construct(public array|Throwable $result)
    {
    }

    public function track(Order $order, array|TrackingOptions $options = []): array
    {
        $this->calls++;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

const TRACKING_PUBLISHER_KEY     = 'fleetops-tracking-publisher-test-key-0123456789';
const TRACKING_PUBLISHER_CONTACT = 'Fleetbase\\FleetOps\\Models\\Contact';

function trackingPublisherBoot(bool $enabled = true): SQLiteConnection
{
    config(['broadcasting.connections.socketcluster.auth_key' => $enabled ? TRACKING_PUBLISHER_KEY : null]);
    config(['broadcasting.connections.socketcluster.publish_url' => 'http://socket.test:8001']);
    config(['broadcasting.connections.socketcluster.options' => ['host' => 'socket.test', 'port' => 8000, 'secure' => false, 'path' => '/socketcluster/', 'query' => []]]);
    Cache::flush();
    DispatchRecorder::$dispatched = [];

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'orders'            => ['uuid', 'public_id', 'company_uuid', 'customer_uuid', 'customer_type', 'driver_assigned_uuid', 'vehicle_assigned_uuid', 'payload_uuid', 'tracking_number_uuid', 'status', 'started', 'started_at'],
        'drivers'           => ['uuid', 'public_id', 'company_uuid', 'user_uuid', 'vehicle_uuid'],
        'users'             => ['uuid', 'public_id', 'company_uuid', 'name'],
        'tracking_statuses' => ['uuid', 'tracking_number_uuid', 'status', 'code', 'complete'],
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

    $connection->table('users')->insert([['uuid' => 'user-1', 'company_uuid' => 'company-1'], ['uuid' => 'user-2', 'company_uuid' => 'company-1']]);
    $connection->table('drivers')->insert([
        ['uuid' => 'driver-1', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1', 'vehicle_uuid' => 'vehicle-1'],
        ['uuid' => 'driver-2', 'company_uuid' => 'company-1', 'user_uuid' => 'user-2', 'vehicle_uuid' => 'vehicle-2'],
    ]);
    $connection->table('orders')->insert([
        ['uuid' => 'order-started', 'payload_uuid' => 'payload-1', 'driver_assigned_uuid' => 'driver-1', 'status' => 'enroute', 'started' => '1'],
        ['uuid' => 'order-status', 'payload_uuid' => 'payload-2', 'driver_assigned_uuid' => 'driver-1', 'status' => 'started', 'started' => null],
        ['uuid' => 'order-timestamp', 'payload_uuid' => 'payload-3', 'driver_assigned_uuid' => 'driver-1', 'status' => 'arrived', 'started' => '0', 'started_at' => '2026-10-06 09:00:00'],
        ['uuid' => 'order-completed', 'payload_uuid' => 'payload-4', 'driver_assigned_uuid' => 'driver-1', 'status' => 'completed', 'started' => '1'],
        ['uuid' => 'order-waiting', 'payload_uuid' => 'payload-5', 'driver_assigned_uuid' => 'driver-1', 'status' => 'dispatched', 'started' => '0'],
        ['uuid' => 'order-other-driver', 'payload_uuid' => 'payload-6', 'driver_assigned_uuid' => 'driver-2', 'status' => 'started', 'started' => '1'],
        ['uuid' => 'order-vehicle', 'payload_uuid' => 'payload-7', 'driver_assigned_uuid' => null, 'vehicle_assigned_uuid' => 'vehicle-1', 'status' => 'started', 'started' => '1'],
    ]);
    $connection->table('orders')->insert(['uuid' => 'order-job', 'company_uuid' => 'company-1', 'customer_uuid' => 'contact-1111', 'customer_type' => TRACKING_PUBLISHER_CONTACT, 'payload_uuid' => 'payload-8', 'status' => 'enroute', 'started' => '1']);

    return $connection;
}

function trackingPublisherModel(string $class, array $attributes): EloquentModel
{
    $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
    $model->setRawAttributes($attributes, true);

    return $model;
}

function trackingPublisherWaypoint(string $publicId, ?string $customerUuid): Waypoint
{
    return trackingPublisherModel(Waypoint::class, ['uuid' => 'uuid-' . $publicId, 'public_id' => $publicId, 'payload_uuid' => 'payload-1', 'customer_uuid' => $customerUuid, 'customer_type' => $customerUuid ? TRACKING_PUBLISHER_CONTACT : null]);
}

function trackingPublisherStops(bool $janeDone = false): Collection
{
    $stop = fn (string $type, int $sequence, bool $completed, ?Waypoint $waypoint) => new TrackingStop(
        uuid: 'place-' . $sequence,
        publicId: 'place_' . $sequence,
        type: $type,
        status: $completed ? 'completed' : 'enroute',
        place: null,
        waypoint: $waypoint,
        completed: $completed,
        sequence: $sequence,
        trackingNumberUuid: 'tn-' . $sequence,
    );

    return collect([
        $stop('pickup', 1, true, null),
        $stop('waypoint', 2, $janeDone, trackingPublisherWaypoint('waypoint_jane', 'contact-2222')),
        $stop('waypoint', 3, false, trackingPublisherWaypoint('waypoint_own', null)),
    ]);
}

function trackingPublisherOrder(array $attributes = []): Order
{
    $driver = trackingPublisherModel(Driver::class, ['uuid' => 'driver-1', 'online' => true, 'heading' => 90, 'location' => new Point(1.352083, 103.819836), 'updated_at' => Carbon::parse('2026-10-06 10:00:00')]);
    $driver->setRelation('user', trackingPublisherModel(User::class, ['name' => 'Ana Lopez']));
    $driver->setRelation('vehicle', null);

    $order = trackingPublisherModel(Order::class, array_merge([
        'uuid'                 => 'order-started',
        'company_uuid'         => 'company-1',
        'customer_type'        => TRACKING_PUBLISHER_CONTACT,
        'customer_uuid'        => 'contact-1111',
        'tracking_number_uuid' => 'tn-order',
        'status'               => 'enroute',
        'started'              => true,
    ], $attributes));
    $order->setRelation('driverAssigned', $driver);
    $order->setRelation('vehicleAssigned', null);

    return $order;
}

function trackingPublisherUseStops(Collection $stops, array|Throwable $tracker = []): TrackingPublisherIntelligenceFake
{
    app()->instance(TrackingContextBuilder::class, new TrackingPublisherContextBuilderFake($stops));
    $intelligence = new TrackingPublisherIntelligenceFake($tracker);
    app()->instance(TrackingIntelligenceService::class, $intelligence);

    return $intelligence;
}

afterEach(function () {
    config(['broadcasting.connections.socketcluster.auth_key' => null]);
    Http::swap(new HttpFactory());
});

test('nothing is queued or published while socket authentication is off', function () {
    trackingPublisherBoot(false);
    $publisher = new TrackingPublisherProbe();
    $order     = trackingPublisherOrder();
    $order->status = 'completed';
    $order->syncChanges();

    expect(TrackingPublisher::enabled())->toBeFalse()
        ->and($publisher->orderChanged($order))->toBeFalse()
        ->and($publisher->stopChanged(trackingPublisherWaypoint('waypoint_own', null)))->toBeFalse()
        ->and($publisher->driverMoved(trackingPublisherModel(Driver::class, ['uuid' => 'driver-1'])))->toBe(0)
        ->and($publisher->vehicleMoved(trackingPublisherModel(Vehicle::class, ['uuid' => 'vehicle-1'])))->toBe(0)
        ->and($publisher->publishOrder($order))->toBe(0)
        ->and($publisher->publishLocation($order))->toBe(0)
        ->and(DispatchRecorder::$dispatched)->toBe([])
        ->and($publisher->sent)->toBe([]);
});

test('order changes queue an update with the most significant reason', function () {
    trackingPublisherBoot();
    $publisher = new TrackingPublisher();
    $order     = trackingPublisherOrder();
    $save      = function (array $changes) use ($order, $publisher): bool {
        foreach ($changes as $key => $value) {
            $order->{$key} = $value;
        }
        $order->syncChanges();
        $queued = $publisher->orderChanged($order);
        $order->syncOriginal();

        return $queued;
    };

    expect($save(['time' => 900, 'distance' => 4000]))->toBeTrue()
        ->and($save(['driver_assigned_uuid' => 'driver-2', 'time' => 950]))->toBeTrue()
        ->and($save(['status' => 'completed', 'distance' => 1]))->toBeTrue()
        ->and($save(['notes' => 'Leave at the door']))->toBeFalse();

    $unsaved = trackingPublisherOrder(['uuid' => null]);
    $unsaved->status = 'started';
    $unsaved->syncChanges();
    expect($publisher->orderChanged($unsaved))->toBeFalse();

    expect(array_map(fn ($dispatch) => [$dispatch['job'], ...$dispatch['arguments']], DispatchRecorder::$dispatched))->toBe([
        [PublishTrackingUpdate::class, 'order-started', 'eta', 'mysql'],
        [PublishTrackingUpdate::class, 'order-started', 'assignment', 'mysql'],
        [PublishTrackingUpdate::class, 'order-started', 'status', 'mysql'],
    ]);
});

test('waypoint and entity activity queue an update for their order through the listener', function () {
    trackingPublisherBoot();
    $listener = new PublishTrackingUpdates();

    $waypointEvent = (new ReflectionClass(WaypointActivityChanged::class))->newInstanceWithoutConstructor();
    $waypointEvent->waypoint = trackingPublisherWaypoint('waypoint_own', null);
    $entityEvent = (new ReflectionClass(EntityCompleted::class))->newInstanceWithoutConstructor();
    $entityEvent->entity = trackingPublisherModel(Entity::class, ['uuid' => 'entity-1', 'payload_uuid' => 'payload-2']);
    $orphanEvent = (new ReflectionClass(EntityCompleted::class))->newInstanceWithoutConstructor();
    $orphanEvent->entity = trackingPublisherModel(Entity::class, ['uuid' => 'entity-2', 'payload_uuid' => 'payload-none']);
    $looseEvent = (new ReflectionClass(EntityCompleted::class))->newInstanceWithoutConstructor();
    $looseEvent->entity = trackingPublisherModel(Entity::class, ['uuid' => 'entity-3', 'payload_uuid' => null]);

    expect($listener->handle($waypointEvent))->toBeTrue()
        ->and($listener->handle($entityEvent))->toBeTrue()
        ->and($listener->handle($orphanEvent))->toBeFalse()
        ->and($listener->handle($looseEvent))->toBeFalse()
        ->and($listener->handle(new stdClass()))->toBeFalse()
        ->and(array_map(fn ($dispatch) => $dispatch['arguments'], DispatchRecorder::$dispatched))->toBe([
            ['order-started', 'stop', 'mysql'],
            ['order-status', 'stop', 'mysql'],
        ]);
});

test('positions queue at most one location update per order and interval', function () {
    trackingPublisherBoot();
    $publisher = new TrackingPublisher();
    $driver    = Driver::where('uuid', 'driver-1')->first();

    // Every en-route order of the driver: started by flag, by status or by timestamp.
    expect($publisher->driverMoved($driver))->toBe(3)
        ->and(array_map(fn ($dispatch) => $dispatch['arguments'], DispatchRecorder::$dispatched))->toBe([
            ['order-started', 'location', 'mysql'],
            ['order-status', 'location', 'mysql'],
            ['order-timestamp', 'location', 'mysql'],
        ]);

    // The same driver again within the interval: no lookup at all.
    expect($publisher->driverMoved($driver))->toBe(0);

    // Another report for the same orders within the interval is dropped per order.
    Cache::forget('fleetops:tracking:driver:driver-1');
    expect($publisher->driverMoved($driver))->toBe(0);

    // The vehicle reaches orders assigned to it and orders of drivers driving it.
    Cache::flush();
    DispatchRecorder::$dispatched = [];
    expect($publisher->vehicleMoved(trackingPublisherModel(Vehicle::class, ['uuid' => 'vehicle-1'])))->toBe(4)
        ->and(array_column(array_column(DispatchRecorder::$dispatched, 'arguments'), 0))->toEqualCanonicalizing(['order-started', 'order-status', 'order-timestamp', 'order-vehicle'])
        ->and($publisher->vehicleMoved(trackingPublisherModel(Vehicle::class, ['uuid' => 'vehicle-1'])))->toBe(0);

    // Records without a uuid are ignored.
    expect($publisher->driverMoved(trackingPublisherModel(Driver::class, [])))->toBe(0)
        ->and($publisher->vehicleMoved(trackingPublisherModel(Vehicle::class, [])))->toBe(0);
});

test('a full update goes to every customer of the order with their own view', function () {
    trackingPublisherBoot();
    $intelligence = trackingPublisherUseStops(trackingPublisherStops(), ['route' => ['legs' => [['stop' => ['sequence' => 3], 'eta_seconds' => 600, 'eta_at' => '2026-10-06T10:11:00.000000Z']]]]);
    $publisher    = new TrackingPublisherProbe();
    $order        = trackingPublisherOrder();

    expect($publisher->publishOrder($order, 'stop'))->toBe(2)
        ->and($intelligence->calls)->toBe(1);

    [$ownChannel, $ownPayload]   = $publisher->sent[0];
    [$janeChannel, $janePayload] = $publisher->sent[1];

    expect($ownChannel)->toBe(TrackingChannel::name(TrackingScope::forOrder($order)))
        ->and($janeChannel)->toBe(TrackingChannel::name(TrackingScope::forStop($order, trackingPublisherWaypoint('waypoint_jane', 'contact-2222'))))
        ->and($ownPayload['reason'])->toBe('stop')
        ->and(array_column($ownPayload['data']['stops'], 'id'))->toBe([null, 'waypoint_own'])
        ->and($ownPayload['data']['stops'][1]['eta']['seconds'])->toBe(600)
        ->and(array_column($janePayload['data']['stops'], 'id'))->toBe(['waypoint_jane'])
        ->and(json_encode($janePayload))->not->toContain('waypoint_own');

    // A publish the socket server refuses is not counted.
    $publisher->accepts = false;
    expect($publisher->publishOrder($order))->toBe(0);
});

test('a position update goes only to customers still waiting for the vehicle', function () {
    trackingPublisherBoot();
    trackingPublisherUseStops(trackingPublisherStops(true));
    $publisher = new TrackingPublisherProbe();
    $order     = trackingPublisherOrder();

    expect($publisher->publishLocation($order))->toBe(1)
        ->and($publisher->sent[0][0])->toBe(TrackingChannel::name(TrackingScope::forOrder($order)))
        ->and($publisher->sent[0][1]['event'])->toBe('tracking.location')
        ->and($publisher->sent[0][1]['data']['location'])->toBe(['latitude' => 1.3521, 'longitude' => 103.8198, 'heading' => 90, 'seen_at' => '2026-10-06T10:00:00.000000Z']);
});

test('etas come from tracking intelligence unless the order is over or it fails', function () {
    trackingPublisherBoot();
    $publisher = new TrackingPublisherProbe();

    $intelligence = trackingPublisherUseStops(collect(), ['route' => ['legs' => []]]);
    expect($publisher->tracker(trackingPublisherOrder()))->toBe(['route' => ['legs' => []]]);

    expect($publisher->tracker(trackingPublisherOrder(['status' => 'Canceled'])))->toBe([])
        ->and($intelligence->calls)->toBe(1);

    trackingPublisherUseStops(collect(), new RuntimeException('routing provider down'));
    expect($publisher->tracker(trackingPublisherOrder()))->toBe([]);
});

test('updates are published through the socket server publish endpoint', function () {
    trackingPublisherBoot();
    Http::fake(['*' => Http::response(['published' => 1], 202)]);

    expect((new TrackingPublisherProbe())->realSend('tracking.abcdefghijklmnopqrstuvwxyz', ['event' => 'tracking.updated']))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/publish') && str_contains($request->body(), 'tracking.abcdefghijklmnopqrstuvwxyz'));
});

test('the queued job publishes the right kind of update', function () {
    trackingPublisherBoot();
    trackingPublisherUseStops(trackingPublisherStops());
    $publisher = new TrackingPublisherProbe();

    // A missing order publishes nothing.
    expect((new PublishTrackingUpdate('order-gone'))->handle($publisher))->toBe(0);

    // A status update publishes the full update to both customers.
    expect((new PublishTrackingUpdate('order-job', 'status', 'mysql'))->handle($publisher))->toBe(2)
        ->and(array_column(array_column($publisher->sent, 1), 'reason'))->toBe(['status', 'status']);

    // The first position in an ETA interval refreshes the full update...
    $publisher->sent = [];
    expect((new PublishTrackingUpdate('order-job', 'location'))->handle($publisher))->toBe(2)
        ->and(array_column(array_column($publisher->sent, 1), 'reason'))->toBe(['eta', 'eta']);

    // ...later ones publish the position only, and this order has no vehicle to show.
    $publisher->sent = [];
    expect((new PublishTrackingUpdate('order-job', 'location'))->handle($publisher))->toBe(0)
        ->and($publisher->sent)->toBe([]);

    $job = new PublishTrackingUpdate('order-job');
    expect($job->reason)->toBe('status')
        ->and($job->dbConnection)->toBeNull()
        ->and($job->tries)->toBe(1);
});
