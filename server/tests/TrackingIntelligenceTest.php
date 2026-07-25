<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Tracking\Providers\CalculatedTrackingProvider;
use Fleetbase\FleetOps\Tracking\Providers\GoogleRoutesTrackingProvider;
use Fleetbase\FleetOps\Tracking\Support\FakeTrackingProvider;
use Fleetbase\FleetOps\Tracking\TrackingContext;
use Fleetbase\FleetOps\Tracking\TrackingContextBuilder;
use Fleetbase\FleetOps\Tracking\TrackingOptions;
use Fleetbase\FleetOps\Tracking\TrackingProviderManager;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\FleetOps\Tracking\TrackingProviderResult;
use Fleetbase\FleetOps\Tracking\TrackingStop;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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

class GoogleRoutesTrackingProviderProbe extends GoogleRoutesTrackingProvider
{
    public ?string $fakeApiKey = null;

    protected function apiKey(): ?string
    {
        return $this->fakeApiKey;
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

function resetTrackingHttp(): void
{
    Http::swap(new HttpFactory());
}

function googleRoutesTrackingContext(): TrackingContext
{
    $pickup = new TrackingStop(
        uuid: 'pickup-uuid',
        publicId: 'place_pickup',
        type: 'pickup',
        status: 'pending',
        place: trackingPlace('66666666-6666-6666-6666-666666666666', 1.30, 103.80),
        completed: false,
        sequence: 1,
        trackingNumberUuid: 'tracking-uuid',
    );
    $dropoff = new TrackingStop(
        uuid: 'dropoff-uuid',
        publicId: 'place_dropoff',
        type: 'dropoff',
        status: 'pending',
        place: trackingPlace('77777777-7777-7777-7777-777777777777', 1.35, 103.85),
        completed: false,
        sequence: 2,
        trackingNumberUuid: 'tracking-uuid',
    );

    return new TrackingContext(
        order: trackingModel(TrackingTestOrder::class),
        payload: null,
        driver: null,
        origin: new Point(1.29, 103.79),
        driverLocation: null,
        stops: collect([$pickup, $dropoff]),
        completedStops: collect(),
        remainingStops: collect([$pickup, $dropoff]),
        activeStop: $pickup,
        nextStop: $dropoff,
        driverLocationAgeSeconds: null,
    );
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

test('calculated provider requires enough route points and guards minimum speed', function () {
    $emptyContext = new TrackingContext(
        order: trackingModel(TrackingTestOrder::class),
        payload: null,
        driver: null,
        origin: null,
        driverLocation: null,
        stops: collect(),
        completedStops: collect(),
        remainingStops: collect(),
        activeStop: null,
        nextStop: null,
        driverLocationAgeSeconds: null,
    );
    $provider = new CalculatedTrackingProvider();
    $method   = new ReflectionMethod($provider, 'durationFromDistance');
    $method->setAccessible(true);

    expect($provider->key())->toBe('calculated')
        ->and($provider->capabilities()->toArray())->toBe([
            'traffic'        => false,
            'per_leg_eta'    => false,
            'map_matching'   => false,
            'route_geometry' => false,
        ])
        ->and($provider->canTrack($emptyContext))->toBeFalse()
        ->and($method->invoke($provider, 1000.0, TrackingOptions::fromArray([
            'default_vehicle_speed_kph' => 0,
        ])))->toBe(3600.0);
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

test('provider manager returns none result with accumulated warnings when providers are unavailable', function () {
    $manager = new TrackingProviderManager(new TrackingProviderRegistry());
    $context = new TrackingContext(
        order: trackingModel(TrackingTestOrder::class),
        payload: null,
        driver: null,
        origin: null,
        driverLocation: null,
        stops: collect(),
        completedStops: collect(),
        remainingStops: collect(),
        activeStop: null,
        nextStop: null,
        driverLocationAgeSeconds: null,
    );

    $result = $manager->track($context, TrackingOptions::fromArray([
        'provider'  => 'missing',
        'fallbacks' => ['calculated'],
    ]));

    expect($result->provider)->toBe('none')
        ->and($result->confidence)->toBe('none')
        ->and($result->warnings)->toContain('provider_not_registered:missing')
        ->and($result->warnings)->toContain('provider_not_registered:calculated')
        ->and($result->warnings)->toContain('no_tracking_provider_available');
});

test('provider manager normalizes and deduplicates provider order', function () {
    $manager = new TrackingProviderManager(new TrackingProviderRegistry());
    $method  = new ReflectionMethod($manager, 'providerOrder');
    $method->setAccessible(true);

    $order = $method->invoke($manager, TrackingOptions::fromArray([
        'provider'  => 'Google Routes',
        'fallbacks' => ['google_routes', 'OSRM', 'calculated', 'OSRM'],
    ]));

    expect($order)->toBe(['google_routes', 'osrm', 'calculated']);
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

test('tracking options normalize explicit scalar settings', function () {
    $options = TrackingOptions::fromArray([
        'provider'                           => 'google_routes',
        'fallbacks'                          => ['osrm'],
        'traffic_enabled'                    => false,
        'cache_ttl_seconds'                  => '45',
        'route_cache_ttl_seconds'            => '1200',
        'stale_location_threshold_seconds'   => '180',
        'default_vehicle_speed_kph'          => '42.5',
    ]);

    expect($options->provider)->toBe('google_routes')
        ->and($options->fallbacks)->toBe(['osrm'])
        ->and($options->trafficEnabled)->toBeFalse()
        ->and($options->cacheTtlSeconds)->toBe(45)
        ->and($options->routeCacheTtlSeconds)->toBe(1200)
        ->and($options->staleLocationThresholdSeconds)->toBe(180)
        ->and($options->defaultVehicleSpeedKph)->toBe(42.5)
        ->and($options->raw['provider'])->toBe('google_routes');
});

test('tracking result stores provider route details without mutation', function () {
    $result = new TrackingProviderResult(
        provider: 'osrm',
        distanceMeters: 1234.5,
        durationSeconds: 600.0,
        durationInTrafficSeconds: 720.0,
        polyline: 'encoded-route',
        coordinates: [[103.8, 1.3]],
        legs: [['distance_m' => 1234.5]],
        warnings: ['traffic_unavailable'],
        confidence: 'high',
        raw: ['source' => 'fixture'],
    );

    expect($result->provider)->toBe('osrm')
        ->and($result->distanceMeters)->toBe(1234.5)
        ->and($result->durationSeconds)->toBe(600.0)
        ->and($result->durationInTrafficSeconds)->toBe(720.0)
        ->and($result->polyline)->toBe('encoded-route')
        ->and($result->coordinates)->toBe([[103.8, 1.3]])
        ->and($result->legs)->toBe([['distance_m' => 1234.5]])
        ->and($result->warnings)->toBe(['traffic_unavailable'])
        ->and($result->confidence)->toBe('high')
        ->and($result->raw)->toBe(['source' => 'fixture']);
});

test('tracking stops serialize empty place data safely', function () {
    $stop = new TrackingStop(
        uuid: 'stop-1',
        publicId: 'stop_public',
        type: 'dropoff',
        status: 'pending',
        place: null,
        completed: false,
        sequence: 2,
        trackingNumberUuid: 'tracking-1',
    );

    expect($stop->point())->toBeNull()
        ->and($stop->toArray())->toMatchArray([
            'uuid'                 => 'stop-1',
            'public_id'            => 'stop_public',
            'type'                 => 'dropoff',
            'status'               => 'pending',
            'completed'            => false,
            'sequence'             => 2,
            'tracking_number_uuid' => 'tracking-1',
            'address'              => null,
            'name'                 => null,
            'location'             => null,
            'latitude'             => null,
            'longitude'            => null,
        ])
        ->and($stop->jsonSerialize())->toBe($stop->toArray());
});

test('tracking provider registry normalizes custom keys and returns all providers', function () {
    $provider = new FakeTrackingProvider('google-routes');
    $registry = new TrackingProviderRegistry();

    $returned = $registry->register($provider, 'Google Routes');

    expect($returned)->toBe($registry)
        ->and($registry->has('google_routes'))->toBeTrue()
        ->and($registry->has('Google Routes'))->toBeTrue()
        ->and($registry->get('google_routes'))->toBe($provider)
        ->and($registry->all())->toBe(['google_routes' => $provider])
        ->and($registry->get('missing'))->toBeNull();
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

test('google routes provider exposes capabilities and requires routable context with api key', function () {
    $provider = new GoogleRoutesTrackingProviderProbe();
    $context  = googleRoutesTrackingContext();

    expect($provider->key())->toBe('google_routes')
        ->and($provider->capabilities()->toArray())->toBe([
            'traffic'        => true,
            'per_leg_eta'    => true,
            'map_matching'   => false,
            'route_geometry' => true,
        ])
        ->and($provider->canTrack($context))->toBeFalse();

    $provider->fakeApiKey = 'test-api-key';
    expect($provider->canTrack($context))->toBeTrue();
});

test('google routes provider posts route points and maps route response with traffic', function () {
    resetTrackingHttp();
    app('config')->set('services.google_maps.api_key', 'test-api-key');

    $provider = new GoogleRoutesTrackingProvider();
    $context  = googleRoutesTrackingContext();
    $sentBody = null;

    Http::fake(function ($request) use (&$sentBody) {
        $sentBody = $request->data();

        return Http::response([
            'routes' => [[
                'distanceMeters' => 7200,
                'duration'       => '900s',
                'staticDuration' => '780s',
                'polyline'       => ['encodedPolyline' => 'encoded-polyline'],
                'legs'           => [
                    ['distanceMeters' => 3000, 'duration' => '420s', 'staticDuration' => '360s'],
                    ['distanceMeters' => 4200, 'duration' => '480s', 'staticDuration' => '420s'],
                ],
            ]],
        ]);
    });

    $result = $provider->track($context, new TrackingOptions(trafficEnabled: true));

    expect($result->provider)->toBe('google_routes')
        ->and($result->distanceMeters)->toBe(7200.0)
        ->and($result->durationSeconds)->toBe(780.0)
        ->and($result->durationInTrafficSeconds)->toBe(900.0)
        ->and($result->polyline)->toBe('encoded-polyline')
        ->and($result->confidence)->toBe('high')
        ->and($result->legs)->toBe([
            [
                'index'                 => 0,
                'distance_m'            => 3000,
                'duration_s'            => 360.0,
                'duration_in_traffic_s' => 420.0,
                'provider'              => 'google_routes',
            ],
            [
                'index'                 => 1,
                'distance_m'            => 4200,
                'duration_s'            => 420.0,
                'duration_in_traffic_s' => 480.0,
                'provider'              => 'google_routes',
            ],
        ]);

    expect(data_get($sentBody, 'origin.location.latLng.latitude'))->toBe(1.29)
        ->and(data_get($sentBody, 'destination.location.latLng.latitude'))->toBe(1.35)
        ->and(data_get($sentBody, 'intermediates.0.location.latLng.latitude'))->toBe(1.30)
        ->and(data_get($sentBody, 'routingPreference'))->toBe('TRAFFIC_AWARE_OPTIMAL');
});

test('google routes provider handles non-traffic confidence and failed route responses', function () {
    resetTrackingHttp();
    app('config')->set('services.google_maps.api_key', 'test-api-key');

    $provider = new GoogleRoutesTrackingProvider();
    $context  = googleRoutesTrackingContext();

    Http::fake([
        'https://routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'distanceMeters' => 1000,
                'duration'       => '100s',
                'legs'           => [
                    ['distanceMeters' => 1000, 'duration' => '100s'],
                ],
            ]],
        ]),
    ]);

    $result = $provider->track($context, new TrackingOptions(trafficEnabled: false));

    expect($result->durationSeconds)->toBe(100.0)
        ->and($result->durationInTrafficSeconds)->toBeNull()
        ->and($result->confidence)->toBe('medium')
        ->and($result->legs[0]['duration_in_traffic_s'])->toBeNull();

    resetTrackingHttp();
    Http::fake(['https://routes.googleapis.com/*' => Http::response([], 500)]);
    expect(fn () => $provider->track($context, new TrackingOptions()))
        ->toThrow(RuntimeException::class, 'Google Routes request failed with status 500');

    resetTrackingHttp();
    Http::fake(['https://routes.googleapis.com/*' => Http::response(['routes' => []])]);
    expect(fn () => $provider->track($context, new TrackingOptions()))
        ->toThrow(RuntimeException::class, 'Google Routes returned no route.');
});
