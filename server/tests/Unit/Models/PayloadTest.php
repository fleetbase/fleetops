<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \FleetOpsPayloadUnitEventRecorder::$events[] = $event; return $event; }');
}

use Fleetbase\FleetOps\Events\EntityActivityChanged;
use Fleetbase\FleetOps\Events\EntityCompleted;
use Fleetbase\FleetOps\Events\WaypointActivityChanged;
use Fleetbase\FleetOps\Events\WaypointCompleted;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\SQLiteConnection;

class FleetOpsPayloadUnitRelationCountFake
{
    public function __construct(private readonly Payload $payload, private readonly string $relation)
    {
    }

    public function count(): int
    {
        return $this->payload->getRelation($this->relation)?->count() ?? 0;
    }
}

class FleetOpsPayloadUnitDatabaseProbe
{
    public function __construct(private SQLiteConnection $connection)
    {
    }

    public function connection(): SQLiteConnection
    {
        return $this->connection;
    }
}

class FleetOpsPayloadUnitOrderFake extends Order
{
    public bool $distanceAndTimeSet = false;

    public function setDistanceAndTime(array $options = []): Order
    {
        $this->distanceAndTimeSet = true;

        return $this;
    }
}

class FleetOpsPayloadUnitEventRecorder
{
    public static array $events = [];
}

class FleetOpsPayloadUnitPlaceFake extends Place
{
    public function getAddressAttribute(): ?string
    {
        return $this->attributes['address'] ?? null;
    }
}

class FleetOpsPayloadUnitActivityWaypointFake extends Waypoint
{
    public array $activityInserts = [];

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityInserts[] = [$activity->code, $location, $proof];

        return 'waypoint-activity-uuid';
    }
}

class FleetOpsPayloadUnitActivityEntityFake extends Entity
{
    public array $activityInserts = [];

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activityInserts[] = [$activity->code, $location, $proof];

        return 'entity-activity-uuid';
    }
}

class FleetOpsPayloadUnitWaypointFake extends Waypoint
{
    public bool $completeForTest = false;

    public function __construct(private ?Place $placeForTest = null, array $attributes = [])
    {
        parent::__construct($attributes);
    }

    public function getPlace(): ?Place
    {
        return $this->placeForTest;
    }

    public function getCompleteAttribute(): bool
    {
        return $this->completeForTest;
    }
}

class FleetOpsPayloadUnitActivityFake extends Payload
{
    public array $loadedMissingRelations = [];

    public function loadMissing($relations)
    {
        $this->loadedMissingRelations[] = $relations;

        return $this;
    }
}

class FleetOpsPayloadUnitFake extends Payload
{
    public array $loadedMissingRelations = [];
    public array $loadedRelations        = [];
    public array $quietUpdates           = [];
    public bool $quietlySaved            = false;
    public bool $pickupIsDriverLocation  = false;
    public array $activityUpdates        = [];
    public array $currentWaypointWrites  = [];

    public function waypoints()
    {
        return new FleetOpsPayloadUnitRelationCountFake($this, 'waypoints');
    }

    public function entities()
    {
        return new FleetOpsPayloadUnitRelationCountFake($this, 'entities');
    }

    public function hasMeta($key): bool
    {
        return $key === 'pickup_is_driver_location' && $this->pickupIsDriverLocation;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissingRelations[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function updateQuietly(array $attributes = [], array $options = [])
    {
        $this->quietUpdates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function saveQuietly(array $options = [])
    {
        $this->quietlySaved = true;

        return true;
    }

    public function updateWaypointActivity(?Activity $activity = null, $location = null, $proof = null)
    {
        $this->activityUpdates[] = [$activity?->code, $location, $proof];

        return $this;
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        if ($destination instanceof Waypoint) {
            $destination = $destination->getPlace();
        }

        $this->currentWaypointWrites[] = [$destination?->uuid, $save];
        $this->current_waypoint_uuid   = $destination?->uuid;

        return $this;
    }
}

function fleetopsPayloadUnitPlace(string $uuid, array $attributes = []): FleetOpsPayloadUnitPlaceFake
{
    $place = new FleetOpsPayloadUnitPlaceFake();
    $place->setRawAttributes(array_merge([
        'uuid'      => $uuid,
        'public_id' => 'place_' . str_replace('-', '_', $uuid),
        'name'      => 'Place ' . $uuid,
        'country'   => 'SG',
    ], $attributes), true);

    return $place;
}

function fleetopsPayloadUnitPayload(array $attributes = []): FleetOpsPayloadUnitFake
{
    $payload = new FleetOpsPayloadUnitFake();
    $payload->setRawAttributes(array_merge(['uuid' => 'payload-uuid'], $attributes), true);
    $payload->setRelation('pickup', null);
    $payload->setRelation('dropoff', null);
    $payload->setRelation('return', null);
    $payload->setRelation('order', null);
    $payload->setRelation('entities', collect());
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());

    return $payload;
}

function fleetopsPayloadUnitUseRelationConnection(): void
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table waypoints (uuid varchar(64), payload_uuid varchar(64), place_uuid varchar(64), deleted_at datetime null, updated_at datetime null)');
    $resolver   = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);

    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new FleetOpsPayloadUnitDatabaseProbe($connection));
}

test('payload relationship contracts resolve expected relation types and models', function () {
    fleetopsPayloadUnitUseRelationConnection();

    $payload = new Payload();

    expect($payload->entities())->toBeInstanceOf(HasMany::class)
        ->and($payload->entities()->getRelated())->toBeInstanceOf(Entity::class)
        ->and($payload->waypointMarkers())->toBeInstanceOf(HasMany::class)
        ->and($payload->waypointMarkers()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->firstWaypointMarker())->toBeInstanceOf(HasOne::class)
        ->and($payload->firstWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->lastWaypointMarker())->toBeInstanceOf(HasOne::class)
        ->and($payload->lastWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->order())->toBeInstanceOf(HasOne::class)
        ->and($payload->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($payload->dropoff())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->dropoff()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->pickup())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->pickup()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->return())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->return()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->currentWaypoint())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->currentWaypoint()->getRelated())->toBeInstanceOf(Place::class)
        ->and($payload->currentWaypointMarker())->toBeInstanceOf(BelongsTo::class)
        ->and($payload->currentWaypointMarker()->getRelated())->toBeInstanceOf(Waypoint::class)
        ->and($payload->waypoints())->toBeInstanceOf(HasManyThrough::class)
        ->and($payload->waypoints()->getRelated())->toBeInstanceOf(Place::class);
});

test('payload exposes endpoint names cod amount and relation count accessors', function () {
    $pickup   = fleetopsPayloadUnitPlace('pickup-uuid', ['address' => 'Pickup Address']);
    $dropoff  = fleetopsPayloadUnitPlace('dropoff-uuid', ['name' => null, 'street1' => 'Dropoff Street']);
    $return   = fleetopsPayloadUnitPlace('return-uuid', ['name' => 'Return Name']);
    $waypoint = fleetopsPayloadUnitPlace('waypoint-uuid', ['address' => 'Waypoint Address']);
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('entities', collect(['box', 'crate']));
    $payload->setRelation('waypoints', collect([$waypoint]));

    $payload->cod_amount = '$1,234.56';

    expect($payload->dropoff_name)->toBe('Dropoff Street')
        ->and($payload->pickup_name)->toBe('Pickup Address')
        ->and($payload->return_name)->toBe('Return Name')
        ->and($payload->cod_amount)->toBe(123456)
        ->and($payload->total_entities)->toBe(2)
        ->and($payload->total_waypoints)->toBe(1);
});

test('payload resolves pickup and dropoff fallbacks from waypoints and driver-location meta', function () {
    $first   = fleetopsPayloadUnitPlace('11111111-1111-4111-8111-111111111111', ['address' => 'First Stop', 'country' => 'MY']);
    $last    = fleetopsPayloadUnitPlace('22222222-2222-4222-8222-222222222222', ['address' => 'Last Stop', 'country' => 'ID']);
    $dropoff = fleetopsPayloadUnitPlace('dropoff-uuid', ['address' => 'Dropoff']);
    $payload = fleetopsPayloadUnitPayload(['current_waypoint_uuid' => '22222222-2222-4222-8222-222222222222']);
    $payload->setRelation('waypoints', collect([$first, $last]));

    expect($payload->getPickupOrFirstWaypoint())->toBe($first)
        ->and($payload->getDropoffOrLastWaypoint())->toBe($last)
        ->and($payload->getPickupOrCurrentWaypoint())->toBe($last)
        ->and($payload->getPickupRegion())->toBe('ID')
        ->and($payload->getCountryCode())->toBe('ID');

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('dropoff', $dropoff);
    $payload->pickupIsDriverLocation = true;

    expect($payload->getPickupOrCurrentWaypoint())->toBe($dropoff);
});

test('payload resolves index pickup and dropoff fallbacks from waypoint marker places', function () {
    $firstPlace  = fleetopsPayloadUnitPlace('first-place-uuid');
    $lastPlace   = fleetopsPayloadUnitPlace('last-place-uuid');
    $pickup      = fleetopsPayloadUnitPlace('pickup-uuid');
    $dropoff     = fleetopsPayloadUnitPlace('dropoff-uuid');
    $firstMarker = new Waypoint();
    $lastMarker  = new Waypoint();

    $firstMarker->setRelation('place', $firstPlace);
    $lastMarker->setRelation('place', $lastPlace);

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('firstWaypointMarker', $firstMarker);
    $payload->setRelation('lastWaypointMarker', $lastMarker);

    expect($payload->index_pickup_place)->toBe($firstPlace)
        ->and($payload->index_dropoff_place)->toBe($lastPlace)
        ->and($payload->loadedMissingRelations)->toContain('firstWaypointMarker.place')
        ->and($payload->loadedMissingRelations)->toContain('lastWaypointMarker.place');

    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);

    expect($payload->index_pickup_place)->toBe($pickup)
        ->and($payload->index_dropoff_place)->toBe($dropoff);
});

test('payload composes stops and pickup locations with sensible fallbacks', function () {
    $pickup   = fleetopsPayloadUnitPlace('pickup-uuid', ['location' => new Point(1.23, 4.56)]);
    $dropoff  = fleetopsPayloadUnitPlace('dropoff-uuid');
    $waypoint = fleetopsPayloadUnitPlace('waypoint-uuid');
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$waypoint, ['uuid' => 'array-stop', 'name' => 'Array Stop']]));

    $stops = $payload->getAllStops()->values();

    expect($stops)->toHaveCount(4)
        ->and($stops[0])->toBe($pickup)
        ->and($stops[1])->toBe($dropoff)
        ->and($stops[2])->toBe($waypoint)
        ->and($stops[3])->toBeInstanceOf(Place::class)
        ->and($payload->getPickupLocation())->toBe($pickup->location);

    $empty = fleetopsPayloadUnitPayload();

    expect($empty->getPickupLocation())->toBeInstanceOf(Point::class);
});

test('payload removes places and invokes callbacks for single and bulk removals', function () {
    $payload = fleetopsPayloadUnitPayload([
        'pickup_uuid'  => 'pickup-uuid',
        'dropoff_uuid' => 'dropoff-uuid',
    ]);
    $payload->setRelation('pickup', fleetopsPayloadUnitPlace('pickup-uuid'));
    $payload->setRelation('dropoff', fleetopsPayloadUnitPlace('dropoff-uuid'));
    $callbacks = 0;

    $result = $payload->removePlace(['pickup', 'dropoff', 99], [
        'save'     => true,
        'callback' => function (Payload $payload) use (&$callbacks): void {
            $callbacks++;
            expect($payload)->toBeInstanceOf(Payload::class);
        },
    ]);

    expect($result)->toBe($payload)
        ->and($payload->pickup_uuid)->toBeNull()
        ->and($payload->dropoff_uuid)->toBeNull()
        ->and($payload->getRelation('pickup'))->toBeNull()
        ->and($payload->getRelation('dropoff'))->toBeNull()
        ->and($payload->quietUpdates)->toBe([['pickup_uuid' => null], ['dropoff_uuid' => null]])
        ->and($callbacks)->toBe(2);
});

test('payload real mutators remove waypoints set places and handle driver pickup meta', function () {
    fleetopsPayloadUnitUseRelationConnection();

    $pickup  = fleetopsPayloadUnitPlace('pickup-uuid');
    $dropoff = fleetopsPayloadUnitPlace('dropoff-uuid');
    $return  = fleetopsPayloadUnitPlace('return-uuid');

    $payload = new Payload();
    $payload->setRawAttributes(['uuid' => 'payload-uuid'], true);
    $payload->setRelation('waypoints', collect([fleetopsPayloadUnitPlace('waypoint-uuid')]));

    expect($payload->removeWaypoints())->toBe($payload)
        ->and($payload->getRelation('waypoints'))->toHaveCount(0);

    $callbacks = [];

    expect($payload->setPlace('pickup', $pickup, [
        'callback' => function ($place, Payload $payload) use (&$callbacks): void {
            $callbacks[] = [$place->uuid, $payload->pickup_uuid];
        },
    ]))->toBe($payload)
        ->and($payload->pickup_uuid)->toBe('pickup-uuid')
        ->and($payload->getRelation('pickup'))->toBe($pickup)
        ->and($callbacks)->toBe([['pickup-uuid', 'pickup-uuid']])
        ->and($payload->setDropoff($dropoff))->toBe($payload)
        ->and($payload->dropoff_uuid)->toBe('dropoff-uuid')
        ->and($payload->setReturn($return))->toBe($payload)
        ->and($payload->return_uuid)->toBe('return-uuid')
        ->and($payload->setPickup('[driver]'))->toBeNull()
        ->and($payload->getMeta('pickup_is_driver_location'))->toBeTrue();
});

test('payload real current waypoint and fallback helpers handle non uuid current destinations', function () {
    $pickup     = fleetopsPayloadUnitPlace('pickup-uuid', ['country' => 'TH']);
    $firstPlace = fleetopsPayloadUnitPlace('first-place-uuid', ['country' => 'MY']);
    $waypoint   = new FleetOpsPayloadUnitWaypointFake($firstPlace);
    $waypoint->setRawAttributes(['uuid' => 'waypoint-uuid', 'place_uuid' => 'first-place-uuid'], true);

    $payload = fleetopsPayloadUnitPayload([
        'current_waypoint_uuid' => 'not-a-uuid',
    ]);
    $payload->setRelation('waypoints', collect([$firstPlace]));

    expect($payload->getPickupOrCurrentWaypoint())->toBe($firstPlace);

    $payload->setRelation('pickup', $pickup);

    expect($payload->getPickupOrFirstWaypoint())->toBe($pickup)
        ->and($payload->getPickupOrCurrentWaypoint())->toBe($pickup)
        ->and($payload->getPickupRegion())->toBe('TH');

    $payload = new Payload();
    $payload->setRawAttributes([
        'uuid' => 'payload-uuid',
    ], true);

    expect($payload->setCurrentWaypoint($waypoint, false))->toBe($payload)
        ->and($payload->current_waypoint_uuid)->toBe('first-place-uuid');
});

test('payload sets current first and next waypoint destinations without database writes in fakes', function () {
    $pickup                 = fleetopsPayloadUnitPlace('pickup-uuid');
    $firstPlace             = fleetopsPayloadUnitPlace('first-place-uuid');
    $secondPlace            = fleetopsPayloadUnitPlace('second-place-uuid');
    $first                  = new FleetOpsPayloadUnitWaypointFake($firstPlace);
    $second                 = new FleetOpsPayloadUnitWaypointFake($secondPlace);
    $first->completeForTest = true;
    $first->setRawAttributes(['uuid' => 'waypoint-one', 'place_uuid' => 'first-place-uuid'], true);
    $second->setRawAttributes(['uuid' => 'waypoint-two', 'place_uuid' => 'second-place-uuid'], true);

    $payload = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('waypoints', collect([$firstPlace, $secondPlace]));
    $payload->setRelation('waypointMarkers', collect([$first, $second]));

    $activity = new Activity(['code' => 'started']);

    expect($payload->setFirstWaypoint($activity, 'point'))->toBe($payload)
        ->and($payload->current_waypoint_uuid)->toBe('pickup-uuid')
        ->and($payload->quietlySaved)->toBeTrue()
        ->and($payload->activityUpdates)->toBe([['started', 'point', null]])
        ->and($payload->loadedRelations)->toContain('currentWaypoint');

    $payload->current_waypoint_uuid = 'first-place-uuid';

    expect($payload->setNextWaypointDestination())->toBe($payload)
        ->and($payload->currentWaypointWrites)->toBe([['second-place-uuid', true]])
        ->and($payload->getRelation('currentWaypoint'))->toBe($secondPlace);

    $empty = fleetopsPayloadUnitPayload();

    expect($empty->setFirstWaypoint())->toBe($empty)
        ->and($empty->quietlySaved)->toBeFalse();

    $multipleDrop = fleetopsPayloadUnitPayload();
    $multipleDrop->setRelation('pickup', null);
    $multipleDrop->setRelation('waypoints', collect([$firstPlace, $secondPlace]));

    expect($multipleDrop->is_multiple_drop_order)->toBeTrue()
        ->and($multipleDrop->setFirstWaypoint())->toBe($multipleDrop)
        ->and($multipleDrop->current_waypoint_uuid)->toBe('first-place-uuid');
});

test('payload resolves order distance updates and destination keys', function () {
    $pickup   = fleetopsPayloadUnitPlace('33333333-3333-4333-8333-333333333333', ['public_id' => 'place_PICKUP1']);
    $dropoff  = fleetopsPayloadUnitPlace('44444444-4444-4444-8444-444444444444', ['public_id' => 'place_DROPOF1']);
    $waypoint = fleetopsPayloadUnitPlace('11111111-1111-4111-8111-111111111111', ['public_id' => 'place_waypoint']);
    $order    = new FleetOpsPayloadUnitOrderFake();
    $payload  = fleetopsPayloadUnitPayload();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect([$waypoint]));
    $payload->setRelation('order', $order);

    expect($payload->getOrder())->toBe($order)
        ->and($payload->updateOrderDistanceAndTime())->toBe($order)
        ->and($order->distanceAndTimeSet)->toBeTrue()
        ->and($payload->findDestinationFromKey(null))->toBeNull()
        ->and($payload->findDestinationFromKey('0'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('pickup'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('dropoff'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('place_waypoint'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('place_PICKUP1'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('place_DROPOF1'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('11111111-1111-4111-8111-111111111111'))->toBe($waypoint)
        ->and($payload->findDestinationFromKey('33333333-3333-4333-8333-333333333333'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('44444444-4444-4444-8444-444444444444'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey('missing'))->toBeNull();

    $payloadWithoutOrder = fleetopsPayloadUnitPayload();

    expect($payloadWithoutOrder->getOrder())->toBeNull()
        ->and($payloadWithoutOrder->loadedRelations)->toContain('order')
        ->and($payloadWithoutOrder->updateOrderDistanceAndTime())->toBeNull();
});

test('payload updates waypoint activity for multiple drop orders and dispatches lifecycle events', function () {
    FleetOpsPayloadUnitEventRecorder::$events = [];

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $currentWaypoint = new FleetOpsPayloadUnitActivityWaypointFake();
    $currentWaypoint->setRawAttributes([
        'uuid'       => 'waypoint-uuid',
        'place_uuid' => 'current-place-uuid',
    ], true);

    $entity = new FleetOpsPayloadUnitActivityEntityFake();
    $entity->setRawAttributes([
        'uuid'             => 'entity-uuid',
        'destination_uuid' => 'current-place-uuid',
    ], true);

    $payload = new FleetOpsPayloadUnitActivityFake();
    $payload->setRawAttributes([
        'uuid'                  => 'payload-uuid',
        'current_waypoint_uuid' => 'current-place-uuid',
    ], true);
    $payload->setRelation('pickup', null);
    $payload->setRelation('order', $order);
    $payload->setRelation('waypoints', collect([fleetopsPayloadUnitPlace('current-place-uuid')]));
    $payload->setRelation('waypointMarkers', collect([$currentWaypoint]));
    $payload->setRelation('entities', collect([$entity]));

    $activity = new Activity(['code' => 'arrived', 'complete' => false]);

    expect($payload->updateWaypointActivity($activity, 'point', 'proof'))->toBe($payload)
        ->and($payload->loadedMissingRelations)->toBe(['order'])
        ->and($currentWaypoint->activityInserts)->toBe([['arrived', 'point', 'proof']])
        ->and($entity->activityInserts)->toBe([['arrived', 'point', 'proof']])
        ->and(array_map(fn ($event) => $event::class, FleetOpsPayloadUnitEventRecorder::$events))->toBe([
            EntityActivityChanged::class,
            WaypointActivityChanged::class,
        ]);

    FleetOpsPayloadUnitEventRecorder::$events = [];
    $currentWaypoint->activityInserts         = [];
    $entity->activityInserts                  = [];

    $payload->updateWaypointActivity(new Activity(['code' => 'delivered', 'complete' => true]), 'dropoff', null);

    expect($currentWaypoint->activityInserts)->toBe([['delivered', 'dropoff', null]])
        ->and($entity->activityInserts)->toBe([['delivered', 'dropoff', null]])
        ->and(array_map(fn ($event) => $event::class, FleetOpsPayloadUnitEventRecorder::$events))->toBe([
            EntityCompleted::class,
            WaypointCompleted::class,
        ]);
});
