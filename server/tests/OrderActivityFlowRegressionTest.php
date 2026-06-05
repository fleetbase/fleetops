<?php

use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\ResolvesOrderServiceStops;

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
    $waypoint                       = new Waypoint();
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
    $payload       = new Payload();
    $payload->uuid = (string) Illuminate\Support\Str::uuid();
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', $return);
    $payload->setRelation('waypoints', collect($waypointPlaces));
    $payload->setRelation('waypointMarkers', collect($waypointPlaces)->values()->map(fn (Place $place, int $index) => activity_flow_waypoint($payload, $place, $index)));

    return $payload;
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
