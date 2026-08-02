<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FleetOpsResolvesStopsPlaceFake extends Place
{
    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->getRelation($key);
        }

        return $this->attributes[$key] ?? null;
    }
}

class FleetOpsResolvesStopsPayloadFake extends Payload
{
    public array $savedQuietly = [];

    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->getRelation($key);
        }

        return $this->attributes[$key] ?? null;
    }

    public function loadMissing($relations)
    {
        return $this;
    }

    public function saveQuietly(array $options = [])
    {
        $this->savedQuietly[] = $options;

        return true;
    }
}

class FleetOpsResolvesStopsWaypointFake extends Waypoint
{
    public function getAttribute($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->getRelation($key);
        }

        return $this->attributes[$key] ?? null;
    }

    public function loadMissing($relations)
    {
        return $this;
    }
}

class FleetOpsResolvesStopsTrackingNumberFake extends TrackingNumber
{
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }
}

class FleetOpsResolvesStopsNextActivityFake extends Activity
{
    public array $contexts = [];
    public Collection $next;

    public function __construct(array $attributes = [], array $flow = [])
    {
        parent::__construct($attributes, $flow);

        $this->next = collect([new Activity(['code' => 'delivered'])]);
    }

    public function getNext(Order|Waypoint $context)
    {
        $this->contexts[] = $context;

        return $this->next;
    }
}

class FleetOpsResolvesStopsOrderConfigFake extends OrderConfig
{
    public ?Waypoint $nextActivityContext = null;

    public function __construct(public Collection $activities = new Collection())
    {
        parent::__construct();
    }

    public function activities(): Collection
    {
        return $this->activities;
    }

    public function nextActivity(Order|Waypoint|null $context = null): Collection
    {
        $this->nextActivityContext = $context instanceof Waypoint ? $context : null;

        return collect([new Activity(['code' => 'loaded'])]);
    }
}

class FleetOpsResolvesStopsOrderFake extends Order
{
    public ?OrderConfig $configForTest = null;

    public function ensureOrderConfig(): ?OrderConfig
    {
        return $this->configForTest;
    }
}

class FleetOpsResolvesStopsProbe
{
    use ResolvesOrderServiceStops {
        activityLocationPoint as public exposedActivityLocationPoint;
        endpointServiceStopTrackingNumber as protected traitEndpointServiceStopTrackingNumber;
        nextActivitiesForServiceStop as public exposedNextActivitiesForServiceStop;
        payloadCurrentServiceStop as public exposedPayloadCurrentServiceStop;
        payloadServiceStops as public exposedPayloadServiceStops;
        resolveServiceStopFromKey as public exposedResolveServiceStopFromKey;
        serviceStopActivityContext as public exposedServiceStopActivityContext;
        serviceStopIsComplete as public exposedServiceStopIsComplete;
        setPayloadCurrentServiceStop as public exposedSetPayloadCurrentServiceStop;
    }

    public array $statuses                         = [];
    public ?TrackingNumber $endpointTrackingNumber = null;

    protected function trackingNumberStatus(string $trackingNumberUuid): ?TrackingStatus
    {
        return $this->statuses[$trackingNumberUuid] ?? null;
    }

    protected function endpointServiceStopTrackingNumber(Order $order, Payload $payload, array $stop, bool $create = false): ?TrackingNumber
    {
        return $this->endpointTrackingNumber;
    }
}

function fleetops_resolves_stops_place(string $key): Place
{
    $place = new FleetOpsResolvesStopsPlaceFake();
    $place->setRawAttributes([
        'uuid'      => (string) Str::uuid(),
        'public_id' => "place_{$key}",
        'id'        => "place_{$key}_id",
        'name'      => ucfirst($key),
        'country'   => 'SG',
    ], true);

    return $place;
}

function fleetops_resolves_stops_waypoint(Payload $payload, Place $place, int $order): Waypoint
{
    $waypoint = new FleetOpsResolvesStopsWaypointFake();
    $waypoint->setRawAttributes([
        'uuid'                 => (string) Str::uuid(),
        'public_id'            => "waypoint_{$order}",
        'payload_uuid'         => $payload->uuid,
        'place_uuid'           => $place->uuid,
        'order'                => $order,
        'tracking_number_uuid' => null,
    ], true);
    $waypoint->setRelation('place', $place);

    return $waypoint;
}

function fleetops_resolves_stops_payload(?Place $pickup = null, ?Place $dropoff = null, array $waypointPlaces = []): Payload
{
    $payload = new FleetOpsResolvesStopsPayloadFake();
    $payload->setRawAttributes([
        'uuid'                         => (string) Str::uuid(),
        'company_uuid'                 => 'company_test',
        'pickup_tracking_number_uuid'  => null,
        'dropoff_tracking_number_uuid' => null,
        'current_waypoint_uuid'        => null,
    ], true);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect($waypointPlaces));
    $payload->setRelation('waypointMarkers', collect($waypointPlaces)->values()->map(fn (Place $place, int $index) => fleetops_resolves_stops_waypoint($payload, $place, $index + 1)));

    return $payload;
}

function fleetops_resolves_stops_order(Payload $payload, string $status = 'started'): Order
{
    $order = new FleetOpsResolvesStopsOrderFake();
    $order->setRawAttributes([
        'uuid'         => (string) Str::uuid(),
        'company_uuid' => 'company_test',
        'status'       => $status,
    ], true);
    $order->setRelation('payload', $payload);

    return $order;
}

function fleetops_resolves_stops_status(bool $complete, string $code = 'arrived'): TrackingStatus
{
    $status = new TrackingStatus();
    $status->setRawAttributes([
        'uuid'     => (string) Str::uuid(),
        'code'     => $code,
        'complete' => $complete,
    ], true);

    return $status;
}

test('service stops sort valid waypoint markers and fall back to waypoint places', function () {
    $pickup  = fleetops_resolves_stops_place('pickup');
    $first   = fleetops_resolves_stops_place('first');
    $second  = fleetops_resolves_stops_place('second');
    $dropoff = fleetops_resolves_stops_place('dropoff');
    $payload = fleetops_resolves_stops_payload($pickup, $dropoff);
    $probe   = new FleetOpsResolvesStopsProbe();

    $lateMarker         = fleetops_resolves_stops_waypoint($payload, $second, 20);
    $earlyMarker        = fleetops_resolves_stops_waypoint($payload, $first, 10);
    $lateMarker->order  = 2;
    $earlyMarker->order = 1;
    $payload->setRelation('waypointMarkers', collect([
        new FleetOpsResolvesStopsWaypointFake(),
        $lateMarker,
        $earlyMarker,
    ]));

    expect($probe->exposedPayloadServiceStops($payload)->pluck('place.name')->all())
        ->toBe(['Pickup', 'First', 'Second', 'Dropoff']);

    $payload->setRelation('waypointMarkers', 'not-a-collection');
    $payload->setRelation('waypoints', collect([$second, 'ignored', $first]));

    $fallbackStops = $probe->exposedPayloadServiceStops($payload);

    expect($fallbackStops->pluck('type')->all())->toBe(['pickup', 'waypoint', 'waypoint', 'dropoff'])
        ->and($fallbackStops->pluck('place.name')->all())->toBe(['Pickup', 'Second', 'First', 'Dropoff']);
});

test('service stop keys and current stop setters include waypoint identifiers', function () {
    $pickup   = fleetops_resolves_stops_place('pickup');
    $waypoint = fleetops_resolves_stops_place('middle');
    $dropoff  = fleetops_resolves_stops_place('dropoff');
    $payload  = fleetops_resolves_stops_payload($pickup, $dropoff, [$waypoint]);
    $probe    = new FleetOpsResolvesStopsProbe();
    $marker   = $payload->waypointMarkers->first();

    $payload->current_waypoint_uuid = $marker->public_id;

    expect($probe->exposedPayloadCurrentServiceStop($payload)['place'])->toBe($waypoint)
        ->and($probe->exposedResolveServiceStopFromKey(null, $pickup->uuid))->toBeNull()
        ->and($probe->exposedResolveServiceStopFromKey($payload, null))->toBeNull()
        ->and($probe->exposedResolveServiceStopFromKey($payload, $marker->place_uuid)['waypoint'])->toBe($marker)
        ->and($probe->exposedResolveServiceStopFromKey($payload, $dropoff->id)['place'])->toBe($dropoff)
        ->and($probe->exposedSetPayloadCurrentServiceStop($payload, ['type' => 'missing']))->toBeNull();

    $selected = $probe->exposedSetPayloadCurrentServiceStop($payload, [
        'type'     => 'waypoint',
        'place'    => $waypoint,
        'waypoint' => $marker,
    ]);

    expect($selected)->toBe($waypoint)
        ->and($payload->current_waypoint_uuid)->toBe($waypoint->uuid)
        ->and($payload->currentWaypoint)->toBe($waypoint)
        ->and($payload->currentWaypointMarker)->toBe($marker)
        ->and($payload->savedQuietly)->toHaveCount(1);
});

test('service stop completion respects order states waypoint tracking and endpoint tracking', function () {
    $pickup   = fleetops_resolves_stops_place('pickup');
    $waypoint = fleetops_resolves_stops_place('middle');
    $dropoff  = fleetops_resolves_stops_place('dropoff');
    $payload  = fleetops_resolves_stops_payload($pickup, $dropoff, [$waypoint]);
    $order    = fleetops_resolves_stops_order($payload);
    $probe    = new FleetOpsResolvesStopsProbe();
    $marker   = $payload->waypointMarkers->first();

    expect($probe->exposedServiceStopIsComplete($order, $payload, [
        'type'     => 'waypoint',
        'waypoint' => $marker,
    ]))->toBeFalse();

    $marker->tracking_number_uuid          = 'tracking_waypoint';
    $payload->dropoff_tracking_number_uuid = 'tracking_dropoff';
    $probe->statuses['tracking_waypoint']  = fleetops_resolves_stops_status(true);
    $probe->statuses['tracking_dropoff']   = fleetops_resolves_stops_status(false);

    expect($probe->exposedServiceStopIsComplete($order, $payload, [
        'type'     => 'waypoint',
        'waypoint' => $marker,
    ]))->toBeTrue()
        ->and($probe->exposedServiceStopIsComplete($order, $payload, [
            'type' => 'dropoff',
        ]))->toBeFalse()
        ->and($probe->exposedServiceStopIsComplete(fleetops_resolves_stops_order($payload, 'canceled'), $payload, [
            'type' => 'pickup',
        ]))->toBeTrue()
        ->and($probe->exposedServiceStopIsComplete($order, $payload, [
            'type' => 'unknown',
        ]))->toBeFalse();
});

test('endpoint service stop contexts and next activities avoid database lookups', function () {
    $pickup                               = fleetops_resolves_stops_place('pickup');
    $payload                              = fleetops_resolves_stops_payload($pickup);
    $payload->pickup_tracking_number_uuid = 'tracking_pickup';
    $order                                = fleetops_resolves_stops_order($payload);
    $probe                                = new FleetOpsResolvesStopsProbe();
    $stop                                 = $probe->exposedPayloadServiceStops($payload)->first();

    $trackingNumber = new FleetOpsResolvesStopsTrackingNumberFake();
    $trackingNumber->setRawAttributes([
        'uuid'        => 'tracking_pickup',
        'status_uuid' => 'status_pickup',
    ], true);
    $probe->endpointTrackingNumber        = $trackingNumber;
    $probe->statuses['tracking_pickup']   = fleetops_resolves_stops_status(true, 'arrived');
    $currentActivity                      = new FleetOpsResolvesStopsNextActivityFake(['code' => 'arrived']);
    $order->configForTest                 = new FleetOpsResolvesStopsOrderConfigFake(collect([$currentActivity]));

    $context = $probe->exposedServiceStopActivityContext($order, $payload, $stop);

    expect($context)->toBeInstanceOf(Waypoint::class)
        ->and($context->tracking_number_uuid)->toBe('tracking_pickup')
        ->and($context->type)->toBe('pickup')
        ->and($context->trackingNumber)->toBe($trackingNumber)
        ->and($trackingNumber->loadedMissing)->toBe(['status']);

    $next = $probe->exposedNextActivitiesForServiceStop($order, $payload, $stop);

    expect($next)->toHaveCount(1)
        ->and($next->first()->code)->toBe('delivered')
        ->and($currentActivity->contexts)->toHaveCount(1)
        ->and($currentActivity->contexts[0])->toBeInstanceOf(Waypoint::class);

    $order->configForTest = null;
    expect($probe->exposedNextActivitiesForServiceStop($order, $payload, $stop))->toHaveCount(0);

    $waypointStop          = $probe->exposedPayloadServiceStops(fleetops_resolves_stops_payload(null, null, [$pickup]))->first();
    $order->configForTest  = new FleetOpsResolvesStopsOrderConfigFake();
    $waypointNext          = $probe->exposedNextActivitiesForServiceStop($order, $payload, $waypointStop);

    expect($waypointNext)->toHaveCount(1)
        ->and($order->configForTest->nextActivityContext)->toBe($waypointStop['waypoint']);
});

test('activity location points normalize supported and fallback values', function () {
    $probe = new FleetOpsResolvesStopsProbe();
    $point = new Point(1.3, 103.8);

    expect($probe->exposedActivityLocationPoint($point))->toBe($point)
        ->and($probe->exposedActivityLocationPoint([1.4, 103.9])->getLat())->toBe(1.4)
        ->and($probe->exposedActivityLocationPoint(['lat' => 1.5, 'lng' => 104.0])->getLng())->toBe(104.0)
        ->and($probe->exposedActivityLocationPoint('bad input')->getLat())->toBe(0.0);
});
