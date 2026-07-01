<?php

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Ai\Capabilities\CreateOrderPreviewCapability;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Illuminate\Support\Carbon;

function fleetopsAiCreateOrderCapability()
{
    return (new ReflectionClass(CreateOrderPreviewCapability::class))->newInstanceWithoutConstructor();
}

function fleetopsAiCreateOrderProtectedMethod(string $method): ReflectionMethod
{
    $reflection = new ReflectionClass(CreateOrderPreviewCapability::class);
    $method     = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method;
}

test('create order preview parser extracts pickup and dropoff labels', function (string $prompt, array $expected) {
    $capability = fleetopsAiCreateOrderCapability();
    $method     = fleetopsAiCreateOrderProtectedMethod('addressPairFromPrompt');

    expect($method->invoke($capability, $prompt))->toBe($expected);
})->with([
    'pickup comma and dropoff' => [
        'Create an order with the pickup 16 simon walk singapore, and the dropoff 18 hougang ave',
        ['16 simon walk singapore', '18 hougang ave'],
    ],
    'pickup at and drop off to' => [
        'Create an order pickup at 16 simon walk singapore and drop off to 18 hougang ave',
        ['16 simon walk singapore', '18 hougang ave'],
    ],
    'from to' => [
        'Create an order from 16 simon walk singapore to 18 hougang ave',
        ['16 simon walk singapore', '18 hougang ave'],
    ],
]);

test('create order preview keeps unresolved addresses as provisional places', function () {
    $capability = fleetopsAiCreateOrderCapability();
    $method     = fleetopsAiCreateOrderProtectedMethod('provisionalPlace');

    expect($method->invoke($capability, '16 simon walk singapore'))->toMatchArray([
        'uuid'      => null,
        'name'      => '16 simon walk singapore',
        'address'   => '16 simon walk singapore',
        'latitude'  => null,
        'longitude' => null,
        'source'    => 'unresolved',
    ]);
});

test('create order preview serializes coordinates from place location', function () {
    $capability = fleetopsAiCreateOrderCapability();
    $method     = fleetopsAiCreateOrderProtectedMethod('serializePlace');
    $place      = new Place([
        'name'        => '16 Simon Walk',
        'street1'     => '16 Simon Walk',
        'city'        => 'Singapore',
        'postal_code' => '545870',
        'location'    => new SpatialPoint(1.3621663, 103.8845049),
    ]);

    expect($method->invoke($capability, $place, '16 simon walk singapore'))->toMatchArray([
        'latitude'  => 1.3621663,
        'longitude' => 103.8845049,
    ]);
});

test('create order preview route preview excludes return stops', function () {
    $capability = fleetopsAiCreateOrderCapability();
    $method     = fleetopsAiCreateOrderProtectedMethod('routePreview');

    $preview = $method->invoke($capability, [
        'payload' => [
            'pickup'  => ['address' => '16 Simon Walk', 'latitude' => 1.3621663, 'longitude' => 103.8845049],
            'dropoff' => ['address' => '18 Hougang Avenue 8', 'latitude' => 1.3701, 'longitude' => 103.8912],
            'return'  => ['address' => 'Ignored return', 'latitude' => 1.3, 'longitude' => 103.8],
        ],
    ]);

    expect(collect($preview['stops'])->pluck('role')->all())
        ->toBe(['pickup', 'dropoff'])
        ->and($preview['coordinates'])->toHaveCount(2);
});

test('create order apply draft removes embedded places when endpoint uuids exist', function () {
    $capability = new class extends CreateOrderPreviewCapability {
        public function sanitizeForTest(array $draft): array
        {
            return $this->sanitizeDraftForApply($draft);
        }

        protected function hasExistingPlaceUuid($uuid): bool
        {
            return in_array($uuid, ['pickup_uuid_test', 'dropoff_uuid_test'], true);
        }
    };

    $draft = $capability->sanitizeForTest([
        'payload' => [
            'pickup_uuid'  => 'pickup_uuid_test',
            'dropoff_uuid' => 'dropoff_uuid_test',
            'pickup'       => ['uuid' => 'pickup_uuid_test', 'location' => ['type' => 'Point', 'coordinates' => [103.851, 1.2816]]],
            'dropoff'      => ['uuid' => 'dropoff_uuid_test', 'location' => ['type' => 'Point', 'coordinates' => [103.8318, 1.3048]]],
            'return'       => ['location' => ['type' => 'Point', 'coordinates' => [103.8, 1.3]]],
        ],
    ]);

    expect($draft['payload'])->toHaveKeys(['pickup_uuid', 'dropoff_uuid', 'return'])
        ->and($draft['payload'])->not->toHaveKeys(['pickup', 'dropoff']);
});

test('create order preview resolves relative schedule phrases', function () {
    $timezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Singapore');
    Carbon::setTestNow(Carbon::parse('2026-06-30 15:00:00', 'Asia/Singapore'));

    try {
        $capability = fleetopsAiCreateOrderCapability();
        $method     = fleetopsAiCreateOrderProtectedMethod('scheduledAtFromPrompt');

        expect($method->invoke($capability, 'Create an order scheduled for 3 days from now'))->toBe('2026-07-03T15:00:00+08:00');
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($timezone);
    }
});
