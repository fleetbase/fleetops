<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

if (!function_exists('Fleetbase\FleetOps\Models\event')) {
    eval('namespace Fleetbase\FleetOps\Models; function event($event = null) { \\ActivityFlowPayloadEventRecorder::$events[] = $event; return $event; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class ActivityFlowRelationCountFake
{
    public function __construct(private int $count)
    {
    }

    public function count(): int
    {
        return $this->count;
    }
}

class ActivityFlowPayloadEventRecorder
{
    public static array $events = [];
}

class ActivityFlowPlaceFake extends Place
{
    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->getRelation($key);
        }

        return $this->attributes[$key] ?? null;
    }
}

class ActivityFlowPayloadFake extends Payload
{
    public array $loaded = [];

    public function loadMissing($relations)
    {
        $this->loaded[] = ['missing', $relations];

        return $this;
    }

    public function load($relations)
    {
        $this->loaded[] = ['load', $relations];

        return $this;
    }

    public function waypoints()
    {
        return new ActivityFlowRelationCountFake($this->waypoints?->count() ?? 0);
    }

    public function saveQuietly(array $options = [])
    {
        return true;
    }
}

class ActivityFlowWaypointFake extends Waypoint
{
    public array $insertedActivities = [];

    public function loadMissing($relations)
    {
        return $this;
    }

    public function insertActivity(Fleetbase\FleetOps\Flow\Activity $activity, $location = [], $proof = null): string
    {
        $this->insertedActivities[] = [$activity, $location, $proof];

        return 'waypoint-activity';
    }
}

class ActivityFlowEntityFake extends Fleetbase\FleetOps\Models\Entity
{
    public array $insertedActivities = [];

    public function insertActivity(Fleetbase\FleetOps\Flow\Activity $activity, $location = [], $proof = null): string
    {
        $this->insertedActivities[] = [$activity, $location, $proof];

        return 'entity-activity';
    }
}

class ActivityFlowStopHelper
{
    use ResolvesOrderServiceStops {
        activityLocationPoint as public exposedActivityLocationPoint;
        advanceCurrentServiceStopDestination as public exposedAdvanceCurrentServiceStopDestination;
        endpointTrackingNumberColumn as public exposedEndpointTrackingNumberColumn;
        ensurePayloadCurrentServiceStop as public exposedEnsurePayloadCurrentServiceStop;
        nextIncompleteServiceStop as public exposedNextIncompleteServiceStop;
        payloadCurrentServiceStop as public exposedPayloadCurrentServiceStop;
        payloadHasWaypointMarkers as public exposedPayloadHasWaypointMarkers;
        payloadHasWaypoints as public exposedPayloadHasWaypoints;
        serviceStopIsComplete as public exposedServiceStopIsComplete;
        serviceStopLocationPoint as public exposedServiceStopLocationPoint;
        serviceStopTrackingNumberUuid as public exposedServiceStopTrackingNumberUuid;
        setPayloadCurrentServiceStop as public exposedSetPayloadCurrentServiceStop;
        waypointMarkerIsComplete as public exposedWaypointMarkerIsComplete;
    }

    public array $statuses = [];

    protected function trackingNumberStatus(string $trackingNumberUuid): ?TrackingStatus
    {
        return $this->statuses[$trackingNumberUuid] ?? null;
    }
}

function activity_flow_helper()
{
    return new class {
        use ResolvesOrderServiceStops {
            payloadServiceStops as public serviceStops;
            payloadUsesServiceStopActivity as public usesServiceStopActivity;
            resolveServiceStopFromKey as public resolveStop;
        }
    };
}

function activity_flow_place(string $key): Place
{
    $place = new ActivityFlowPlaceFake();
    $place->setRawAttributes([
        'uuid'      => (string) Illuminate\Support\Str::uuid(),
        'public_id' => "place_{$key}",
        'id'        => "place_{$key}",
        'name'      => ucfirst($key),
        'country'   => 'SG',
    ], true);

    return $place;
}

function activity_flow_waypoint(Payload $payload, Place $place, int $order): Waypoint
{
    $waypoint                       = new ActivityFlowWaypointFake();
    $waypoint->uuid                 = (string) Illuminate\Support\Str::uuid();
    $waypoint->public_id            = "waypoint_{$order}";
    $waypoint->payload_uuid         = $payload->uuid;
    $waypoint->place_uuid           = $place->uuid;
    $waypoint->order                = $order;
    $waypoint->tracking_number_uuid = null;
    $waypoint->setRelation('place', $place);
    $waypoint->setRelation('trackingNumber', null);

    return $waypoint;
}

function activity_flow_payload(?Place $pickup = null, ?Place $dropoff = null, array $waypointPlaces = [], ?Place $return = null): Payload
{
    $payload       = new ActivityFlowPayloadFake();
    $payload->uuid = (string) Illuminate\Support\Str::uuid();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('waypoints', collect($waypointPlaces));
    $payload->setRelation('waypointMarkers', collect($waypointPlaces)->values()->map(fn (Place $place, int $index) => activity_flow_waypoint($payload, $place, $index)));

    return $payload;
}

function activity_flow_order(Payload $payload, string $status = 'started'): Order
{
    $order               = new Order();
    $order->uuid         = (string) Illuminate\Support\Str::uuid();
    $order->company_uuid = 'company_test';
    $order->status       = $status;
    $order->setRelation('payload', $payload);

    return $order;
}

function activity_flow_tracking_status(bool $complete, string $code = 'arrived'): TrackingStatus
{
    $status           = new TrackingStatus();
    $status->uuid     = (string) Illuminate\Support\Str::uuid();
    $status->code     = $code;
    $status->complete = $complete;

    return $status;
}

test('classic pickup and dropoff route stays order activity driven even with a destination param', function () {
    $pickup  = activity_flow_place('pickup');
    $dropoff = activity_flow_place('dropoff');
    $payload = activity_flow_payload($pickup, $dropoff);
    $helper  = activity_flow_helper();

    expect($helper->usesServiceStopActivity($payload))->toBeFalse()
        ->and($helper->resolveStop($payload, $pickup->public_id))->not->toBeNull();
});

test('pickup waypoint and dropoff route uses service stop activity in pickup waypoint dropoff order', function () {
    $pickup   = activity_flow_place('pickup');
    $waypoint = activity_flow_place('middle');
    $dropoff  = activity_flow_place('dropoff');
    $payload  = activity_flow_payload($pickup, $dropoff, [$waypoint]);
    $helper   = activity_flow_helper();
    $stops    = $helper->serviceStops($payload);

    expect($helper->usesServiceStopActivity($payload))->toBeTrue()
        ->and($stops)->toHaveCount(3)
        ->and($stops->pluck('type')->all())->toBe(['pickup', 'waypoint', 'dropoff']);
});

test('pickup plus waypoints without dropoff and waypoint only routes use service stop activity', function () {
    $pickup       = activity_flow_place('pickup');
    $firstStop    = activity_flow_place('first');
    $secondStop   = activity_flow_place('second');
    $withPickup   = activity_flow_payload($pickup, null, [$firstStop, $secondStop]);
    $waypointOnly = activity_flow_payload(null, null, [$firstStop, $secondStop]);
    $helper       = activity_flow_helper();

    expect($helper->usesServiceStopActivity($withPickup))->toBeTrue()
        ->and($helper->serviceStops($withPickup)->pluck('type')->all())->toBe(['pickup', 'waypoint', 'waypoint'])
        ->and($helper->usesServiceStopActivity($waypointOnly))->toBeTrue()
        ->and($helper->serviceStops($waypointOnly)->pluck('type')->all())->toBe(['waypoint', 'waypoint']);
});

test('return address is excluded from normal service stop activity sequence', function () {
    $pickup  = activity_flow_place('pickup');
    $dropoff = activity_flow_place('dropoff');
    $return  = activity_flow_place('return');
    $payload = activity_flow_payload($pickup, $dropoff, [], $return);
    $helper  = activity_flow_helper();

    expect($helper->serviceStops($payload)->pluck('type')->all())->toBe(['pickup', 'dropoff']);
});

test('service stop helper resolves current stop and advances past completed stops', function () {
    $pickup         = activity_flow_place('pickup');
    $middle         = activity_flow_place('middle');
    $dropoff        = activity_flow_place('dropoff');
    $payload        = activity_flow_payload($pickup, $dropoff, [$middle]);
    $order          = activity_flow_order($payload);
    $helper         = new ActivityFlowStopHelper();
    $middleWaypoint = $payload->waypointMarkers->first();

    $payload->current_waypoint_uuid = $middle->uuid;

    expect($helper->exposedPayloadHasWaypoints($payload))->toBeTrue()
        ->and($helper->exposedPayloadHasWaypointMarkers($payload))->toBeTrue()
        ->and($helper->exposedPayloadCurrentServiceStop($payload)['type'])->toBe('waypoint')
        ->and($helper->exposedPayloadCurrentServiceStop($payload)['place'])->toBe($middle)
        ->and($helper->exposedServiceStopTrackingNumberUuid($payload, [
            'type' => 'pickup',
        ]))->toBeNull()
        ->and($helper->exposedEndpointTrackingNumberColumn('pickup'))->toBe('pickup_tracking_number_uuid')
        ->and($helper->exposedEndpointTrackingNumberColumn('dropoff'))->toBe('dropoff_tracking_number_uuid')
        ->and($helper->exposedEndpointTrackingNumberColumn('waypoint'))->toBeNull();

    $middleWaypoint->tracking_number_uuid  = 'tracking_middle';
    $payload->dropoff_tracking_number_uuid = 'tracking_dropoff';
    $helper->statuses['tracking_middle']   = activity_flow_tracking_status(true);
    $helper->statuses['tracking_dropoff']  = activity_flow_tracking_status(false);

    expect($helper->exposedWaypointMarkerIsComplete($middleWaypoint))->toBeTrue()
        ->and($helper->exposedServiceStopIsComplete($order, $payload, [
            'type'     => 'waypoint',
            'waypoint' => $middleWaypoint,
        ]))->toBeTrue()
        ->and($helper->exposedServiceStopIsComplete($order, $payload, [
            'type' => 'dropoff',
        ]))->toBeFalse();

    $next = $helper->exposedAdvanceCurrentServiceStopDestination($order, $payload);

    expect($next['type'])->toBe('dropoff')
        ->and($payload->current_waypoint_uuid)->toBe($dropoff->uuid)
        ->and($payload->currentWaypoint)->toBe($dropoff);
});

test('payload accessors fall back across endpoints and waypoints', function () {
    $pickup   = activity_flow_place('pickup');
    $middle   = activity_flow_place('middle');
    $dropoff  = activity_flow_place('dropoff');
    $fallback = activity_flow_payload(null, null, [$pickup, $middle, $dropoff]);

    $fallback->current_waypoint_uuid = $middle->uuid;

    expect($fallback->getDropoffOrLastWaypoint())->toBe($dropoff)
        ->and($fallback->getPickupOrFirstWaypoint())->toBe($pickup)
        ->and($fallback->getPickupOrCurrentWaypoint())->toBe($middle)
        ->and($fallback->getDropoffNameAttribute())->toBeString()
        ->and($fallback->getPickupNameAttribute())->toBeString()
        ->and($fallback->getPickupRegion())->toBe('SG');

    $driverLocation = activity_flow_payload(null, $dropoff, [$pickup]);
    $driverLocation->setMeta('pickup_is_driver_location', true);

    expect($driverLocation->getPickupOrCurrentWaypoint())->toBe($dropoff)
        ->and($driverLocation->getCountryCode())->toBe('SG');

    $empty = activity_flow_payload();

    expect($empty->getDropoffOrLastWaypoint())->toBeNull()
        ->and($empty->getPickupOrFirstWaypoint())->toBeNull()
        ->and($empty->getPickupOrCurrentWaypoint())->toBeNull();
});

test('payload index and stop helpers normalize loaded places', function () {
    $payload = new ActivityFlowPayloadFake();
    $first   = (object) ['place' => activity_flow_place('first')];
    $last    = (object) ['place' => activity_flow_place('last')];

    $payload->setRelation('pickup', null);
    $payload->setRelation('dropoff', null);
    $payload->setRelation('waypoints', collect([['name' => 'Array Stop'], (object) ['name' => 'ignored']]));
    $payload->setRelation('firstWaypointMarker', $first);
    $payload->setRelation('lastWaypointMarker', $last);

    $stops = $payload->getAllStops()->values();

    expect($payload->index_pickup_place)->toBe($first->place)
        ->and($payload->index_dropoff_place)->toBe($last->place)
        ->and($stops)->toHaveCount(1)
        ->and($stops->first())->toBeInstanceOf(Place::class)
        ->and($stops->first()->name)->toBe('Array Stop');
});

test('payload mutators remove places and choose first waypoint destinations without persistence', function () {
    $pickup  = activity_flow_place('pickup');
    $dropoff = activity_flow_place('dropoff');
    $payload = activity_flow_payload($pickup, $dropoff, [$dropoff]);
    $called  = false;

    $payload->pickup_uuid  = $pickup->uuid;
    $payload->dropoff_uuid = $dropoff->uuid;

    expect($payload->removePlace(['pickup', 'dropoff'], [
        'callback' => function (Payload $callbackPayload) use (&$called, $payload) {
            $called = $callbackPayload === $payload;
        },
    ]))->toBe($payload)
        ->and($payload->pickup_uuid)->toBeNull()
        ->and($payload->dropoff_uuid)->toBeNull()
        ->and($called)->toBeTrue();

    expect($payload->setPickup('[driver]'))->toBeNull()
        ->and($payload->hasMeta('pickup_is_driver_location'))->toBeTrue();

    $waypointOnly = activity_flow_payload(null, null, [$dropoff]);

    expect($waypointOnly->is_multiple_drop_order)->toBeTrue()
        ->and($waypointOnly->setFirstWaypoint())->toBe($waypointOnly)
        ->and($waypointOnly->current_waypoint_uuid)->toBe($dropoff->uuid);
});

test('payload destination resolution supports indexes ids and explicit endpoints', function () {
    $pickup   = activity_flow_place('pickup');
    $middle   = activity_flow_place('middle');
    $dropoff  = activity_flow_place('dropoff');
    $payload  = activity_flow_payload($pickup, $dropoff, [$middle]);
    $uuidOnly = activity_flow_place('uuid-only');

    $pickup->public_id   = 'place_pickup1';
    $middle->public_id   = 'place_middle1';
    $dropoff->public_id  = 'place_dropoff1';
    $uuidOnly->public_id = null;
    $payload->setRelation('waypoints', collect([$middle, $uuidOnly]));

    expect($payload->findDestinationFromKey(null))->toBeNull()
        ->and($payload->findDestinationFromKey('0'))->toBe($middle)
        ->and($payload->findDestinationFromKey('pickup'))->toBe($pickup)
        ->and($payload->findDestinationFromKey('dropoff'))->toBe($dropoff)
        ->and($payload->findDestinationFromKey($middle->public_id))->toBe($middle)
        ->and($payload->findDestinationFromKey($dropoff->public_id))->toBe($dropoff)
        ->and($payload->findDestinationFromKey($uuidOnly->uuid))->toBe($uuidOnly)
        ->and($payload->findDestinationFromKey($pickup->uuid))->toBe($pickup);
});

test('payload waypoint activity updates current waypoint entities and events', function () {
    ActivityFlowPayloadEventRecorder::$events = [];

    $place    = activity_flow_place('dropoff');
    $payload  = activity_flow_payload(null, null, [$place]);
    $order    = activity_flow_order($payload);
    $waypoint = $payload->waypointMarkers->first();
    $entity   = new ActivityFlowEntityFake();
    $activity = new Fleetbase\FleetOps\Flow\Activity([
        'code'     => 'delivered',
        'complete' => true,
    ]);

    $payload->current_waypoint_uuid = $place->uuid;
    $payload->setRelation('order', $order);
    $payload->setRelation('entities', collect([$entity]));
    $entity->destination_uuid = $place->uuid;

    expect($payload->updateWaypointActivity($activity, new Point(1, 2), 'proof-public'))->toBe($payload)
        ->and($waypoint->insertedActivities)->toHaveCount(1)
        ->and($entity->insertedActivities)->toHaveCount(1)
        ->and(collect(ActivityFlowPayloadEventRecorder::$events)->map(fn ($event) => $event::class)->all())->toContain(
            Fleetbase\FleetOps\Events\EntityCompleted::class,
            Fleetbase\FleetOps\Events\WaypointCompleted::class
        );
});

test('service stop helper ensures defaults and normalizes locations without database work', function () {
    $pickup  = activity_flow_place('pickup');
    $dropoff = activity_flow_place('dropoff');
    $payload = activity_flow_payload($pickup, $dropoff);
    $helper  = new ActivityFlowStopHelper();
    $order   = activity_flow_order($payload, 'completed');

    expect($helper->exposedPayloadHasWaypoints(null))->toBeFalse()
        ->and($helper->exposedPayloadHasWaypointMarkers(null))->toBeFalse()
        ->and($helper->exposedPayloadCurrentServiceStop(null))->toBeNull()
        ->and($helper->exposedEnsurePayloadCurrentServiceStop(null))->toBeNull();

    $stop = $helper->exposedEnsurePayloadCurrentServiceStop($payload);

    expect($stop['type'])->toBe('pickup')
        ->and($payload->current_waypoint_uuid)->toBe($pickup->uuid)
        ->and($helper->exposedNextIncompleteServiceStop($order, $payload))->toBeNull()
        ->and($helper->exposedServiceStopIsComplete($order, $payload, $stop))->toBeTrue();

    $pickup->location = new Point(1.3, 103.8);
    expect($helper->exposedServiceStopLocationPoint($pickup))->toBeInstanceOf(Point::class)
        ->and($helper->exposedActivityLocationPoint(new Point(1.4, 103.9))->getLat())->toBe(1.4)
        ->and($helper->exposedActivityLocationPoint([1.5, 104.0])->getLng())->toBe(104.0)
        ->and($helper->exposedActivityLocationPoint('bad input')->getLat())->toBe(0.0);
});

test('pod bypass is internal console only', function () {
    $internalController = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Internal/v1/OrderController.php');
    $publicController   = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/OrderController.php');
    $consoleModal       = file_get_contents(dirname(__DIR__, 2) . '/addon/components/modals/update-order-activity.hbs');

    expect($internalController)
        ->toContain("boolean('bypass_proof')")
        ->and($publicController)
        ->not->toContain('bypass_proof')
        ->and($consoleModal)
        ->toContain('Bypass proof of delivery');
});
