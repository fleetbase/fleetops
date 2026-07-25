<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

class ActivityFlowPayloadFake extends Payload
{
    public function loadMissing($relations)
    {
        return $this;
    }

    public function saveQuietly(array $options = [])
    {
        return true;
    }
}

class ActivityFlowWaypointFake extends Waypoint
{
    public function loadMissing($relations)
    {
        return $this;
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
    $place            = new Place();
    $place->uuid      = (string) Illuminate\Support\Str::uuid();
    $place->public_id = "place_{$key}";
    $place->id        = "place_{$key}";
    $place->name      = ucfirst($key);

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
