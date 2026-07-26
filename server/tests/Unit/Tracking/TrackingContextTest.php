<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;

function fleetopsTrackingContextStop(string $uuid, int $sequence, ?Point $location = null, bool $completed = false): TrackingStop
{
    $place = new Place();
    $place->setRawAttributes([
        'uuid'     => 'place-' . $uuid,
        'name'     => 'Place ' . $uuid,
        'address'  => 'Address ' . $uuid,
        'location' => $location,
    ], true);

    return new TrackingStop(
        $uuid,
        'public-' . $uuid,
        'waypoint',
        $completed ? 'completed' : 'pending',
        $place,
        null,
        $completed,
        $sequence,
        'tracking-' . $uuid,
    );
}

function fleetopsTrackingContext(array $overrides = []): TrackingContext
{
    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-uuid', 'public_id' => 'order-public'], true);

    $pickup  = fleetopsTrackingContextStop('pickup', 1, new Point(1.30, 103.80), true);
    $dropoff = fleetopsTrackingContextStop('dropoff', 2, new Point(1.31, 103.81));
    $get     = fn (string $key, mixed $default = null) => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

    return new TrackingContext(
        $get('order', $order),
        $get('payload'),
        $get('driver'),
        $get('origin', new Point(1.29, 103.79)),
        $get('driverLocation'),
        $get('stops', collect([$pickup, $dropoff])),
        $get('completedStops', collect([$pickup])),
        $get('remainingStops', collect([$dropoff])),
        $get('activeStop', $dropoff),
        $get('nextStop'),
        $get('driverLocationAgeSeconds'),
        $get('warnings', []),
    );
}

test('tracking context builds route points from origin and remaining tracking stop locations', function () {
    $context = fleetopsTrackingContext([
        'remainingStops' => collect([
            fleetopsTrackingContextStop('dropoff', 2, new Point(1.31, 103.81)),
            (object) ['uuid' => 'not-a-tracking-stop'],
            fleetopsTrackingContextStop('empty', 3, null),
        ]),
    ]);

    $points = $context->routePoints();

    expect($points)->toHaveCount(2)
        ->and($points[0]->getLat())->toBe(1.29)
        ->and($points[1]->getLat())->toBe(1.31)
        ->and($context->canRoute())->toBeTrue();
});

test('tracking context cannot route without at least two resolved route points', function () {
    $context = fleetopsTrackingContext([
        'origin'         => null,
        'remainingStops' => collect([
            fleetopsTrackingContextStop('empty', 1, null),
            (object) ['uuid' => 'ignored'],
        ]),
    ]);

    expect($context->routePoints())->toBe([])
        ->and($context->canRoute())->toBeFalse();
});

test('tracking context state signature is stable and reflects stop state changes', function () {
    $pickup  = fleetopsTrackingContextStop('pickup', 1, new Point(1.30, 103.80), true);
    $dropoff = fleetopsTrackingContextStop('dropoff', 2, new Point(1.31, 103.81));

    $first = fleetopsTrackingContext([
        'stops' => collect([$pickup, $dropoff]),
    ]);
    $second = fleetopsTrackingContext([
        'stops' => collect([$pickup, $dropoff]),
    ]);
    $changed = fleetopsTrackingContext([
        'stops' => collect([
            $pickup,
            new TrackingStop('dropoff', 'public-dropoff', 'waypoint', 'completed', $dropoff->place, null, true, 2, 'tracking-dropoff'),
        ]),
    ]);

    expect($first->stateSignature())->toBe($second->stateSignature())
        ->and($first->stateSignature())->not->toBe($changed->stateSignature());
});
