<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Tracking\Providers\CalculatedTrackingProvider;
use Fleetbase\FleetOps\Tracking\Support\FakeTrackingProvider;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderManager;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

function trackingPlace(string $uuid, float $lat, float $lng): Place
{
    $place            = new Place();
    $place->uuid      = $uuid;
    $place->public_id = 'place_' . substr($uuid, 0, 8);
    $place->address   = 'Test address ' . $uuid;
    $place->location  = new Point($lat, $lng);

    return $place;
}

function trackingOrderWithStops(): Order
{
    $pickup                         = trackingPlace('11111111-1111-1111-1111-111111111111', 1.30, 103.80);
    $dropoff                        = trackingPlace('22222222-2222-2222-2222-222222222222', 1.35, 103.85);
    $payload                        = new Payload();
    $payload->uuid                  = '33333333-3333-3333-3333-333333333333';
    $payload->current_waypoint_uuid = $pickup->uuid;
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());

    $driver             = new Driver();
    $driver->uuid       = '44444444-4444-4444-4444-444444444444';
    $driver->location   = new Point(1.29, 103.79);
    $driver->online     = true;
    $driver->updated_at = Carbon::now();

    $order             = new Order();
    $order->uuid       = '55555555-5555-5555-5555-555555555555';
    $order->public_id  = 'order_test';
    $order->status     = 'started';
    $order->updated_at = Carbon::now();
    $order->setRelation('payload', $payload);
    $order->setRelation('driverAssigned', $driver);

    return $order;
}

test('tracking context builder normalizes order stops and driver telemetry', function () {
    $context = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider' => 'calculated',
    ]));

    expect($context->stops)->toHaveCount(2)
        ->and($context->activeStop?->type)->toBe('dropoff')
        ->and($context->nextStop)->toBeNull()
        ->and($context->driverLocationAgeSeconds)->toBeInt()
        ->and($context->warnings)->toBe([]);
});

test('calculated provider returns normalized low confidence route data', function () {
    $context = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider' => 'calculated',
    ]));

    $result = (new CalculatedTrackingProvider())->track($context, TrackingOptions::fromArray([
        'provider'                  => 'calculated',
        'default_vehicle_speed_kph' => 36,
    ]));

    expect($result->provider)->toBe('calculated')
        ->and($result->distanceMeters)->toBeGreaterThan(0)
        ->and($result->durationSeconds)->toBeGreaterThan(0)
        ->and($result->confidence)->toBe('low')
        ->and($result->warnings)->toContain('calculated_route_used');
});

test('provider manager falls back to registered provider and records fallback warning', function () {
    $registry = new TrackingProviderRegistry();
    $registry->register(new FakeTrackingProvider('fake'));
    $manager = new TrackingProviderManager($registry);
    $context = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider'  => 'missing',
        'fallbacks' => ['fake'],
    ]));

    $result = $manager->track($context, TrackingOptions::fromArray([
        'provider'  => 'missing',
        'fallbacks' => ['fake'],
    ]));

    expect($result->provider)->toBe('fake')
        ->and($result->warnings)->toContain('provider_not_registered:missing')
        ->and($result->warnings)->toContain('fallback_used');
});

test('third party providers can be registered through the tracking provider contract', function () {
    $registry = new TrackingProviderRegistry();
    $registry->register(new FakeTrackingProvider('tomtom'));

    expect($registry->has('tomtom'))->toBeTrue()
        ->and($registry->get('tomtom')?->capabilities()->traffic)->toBeTrue();
});
