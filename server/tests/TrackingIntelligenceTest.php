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
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

class TrackingTestOrder extends Order
{
    public function loadMissing($relations)
    {
        return $this;
    }
}

class TrackingTestPayload extends Payload
{
    public function loadMissing($relations)
    {
        return $this;
    }
}

function trackingModel(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function trackingSetAttributes($model, array $attributes): void
{
    $model->setRawAttributes(array_merge($model->getAttributes(), $attributes), true);
}

function trackingPlace(string $uuid, float $lat, float $lng): Place
{
    $place = trackingModel(Place::class);
    trackingSetAttributes($place, [
        'uuid'      => $uuid,
        'public_id' => 'place_' . substr($uuid, 0, 8),
        'address'   => 'Test address ' . $uuid,
        'location'  => new Point($lat, $lng),
    ]);

    return $place;
}

function trackingOrderWithStops(): Order
{
    $pickup                         = trackingPlace('11111111-1111-1111-1111-111111111111', 1.30, 103.80);
    $dropoff                        = trackingPlace('22222222-2222-2222-2222-222222222222', 1.35, 103.85);
    $payload                        = trackingModel(TrackingTestPayload::class);
    trackingSetAttributes($payload, [
        'uuid'                  => '33333333-3333-3333-3333-333333333333',
        'current_waypoint_uuid' => $pickup->uuid,
    ]);
    $payload->setRelation('pickup', $pickup);
    $payload->setRelation('dropoff', $dropoff);
    $payload->setRelation('return', null);
    $payload->setRelation('waypoints', collect());
    $payload->setRelation('waypointMarkers', collect());

    $driver = trackingModel(Driver::class);
    trackingSetAttributes($driver, [
        'uuid'       => '44444444-4444-4444-4444-444444444444',
        'location'   => new Point(1.29, 103.79),
        'online'     => true,
        'updated_at' => Carbon::now(),
    ]);

    $order = trackingModel(TrackingTestOrder::class);
    trackingSetAttributes($order, [
        'uuid'       => '55555555-5555-5555-5555-555555555555',
        'public_id'  => 'order_test',
        'status'     => 'started',
        'updated_at' => Carbon::now(),
    ]);
    $order->setRelation('payload', $payload);
    $order->setRelation('driverAssigned', $driver);

    return $order;
}

test('tracking context builder normalizes order stops and driver telemetry', function () {
    $context = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider' => 'calculated',
    ]));

    expect($context->stops)->toHaveCount(2)
        ->and($context->activeStop?->type)->toBe('pickup')
        ->and($context->nextStop?->type)->toBe('dropoff')
        ->and($context->driverLocationAgeSeconds)->toBeInt()
        ->and($context->warnings)->toBe([]);
});

test('tracking context ignores zero coordinate driver location and falls back to pickup origin', function () {
    $order                           = trackingOrderWithStops();
    trackingSetAttributes($order->driverAssigned, ['location' => new Point(0, 0)]);

    $context = (new TrackingContextBuilder())->build($order, TrackingOptions::fromArray([
        'provider' => 'calculated',
    ]));

    expect($context->driverLocation)->toBeNull()
        ->and($context->origin?->getLat())->toBe(1.30)
        ->and($context->origin?->getLng())->toBe(103.80)
        ->and($context->routePoints()[0]->getLat())->toBe(1.30)
        ->and($context->warnings)->toContain('missing_driver_location');
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

test('tracking route legs include cumulative stop eta values', function () {
    $context = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider' => 'calculated',
    ]));

    $result = new TrackingProviderResult(
        provider: 'test',
        legs: [
            ['duration_s' => 120],
            ['duration_s' => 180],
        ]
    );
    $service = app(Fleetbase\FleetOps\Tracking\TrackingIntelligenceService::class);
    $method  = new ReflectionMethod($service, 'legs');
    $method->setAccessible(true);
    $legs = $method->invoke($service, $context, $result, Carbon::parse('2026-05-12 00:00:00'));

    expect($legs[0]['eta_seconds'])->toBeGreaterThan(0)
        ->and($legs[0]['eta_at'])->not->toBeNull();
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

test('tracking options include route cache ttl and fallback provider settings', function () {
    $options = TrackingOptions::fromArray([
        'provider'                => 'calculated',
        'fallbacks'               => 'osrm,calculated',
        'route_cache_ttl_seconds' => 900,
    ]);

    expect($options->provider)->toBe('calculated')
        ->and($options->fallbacks)->toBe(['osrm', 'calculated'])
        ->and($options->routeCacheTtlSeconds)->toBe(900);
});

test('provider cache key varies by route options', function () {
    $registry = new TrackingProviderRegistry();
    $manager  = new TrackingProviderManager($registry);
    $provider = new FakeTrackingProvider('fake');
    $context  = (new TrackingContextBuilder())->build(trackingOrderWithStops(), TrackingOptions::fromArray([
        'provider' => 'fake',
    ]));
    $method = new ReflectionMethod($manager, 'providerCacheKey');
    $method->setAccessible(true);

    $trafficKey = $method->invoke($manager, $provider, $context, TrackingOptions::fromArray([
        'provider'        => 'fake',
        'traffic_enabled' => true,
    ]));
    $nonTrafficKey = $method->invoke($manager, $provider, $context, TrackingOptions::fromArray([
        'provider'        => 'fake',
        'traffic_enabled' => false,
    ]));

    expect($trafficKey)->not->toBe($nonTrafficKey);
});
