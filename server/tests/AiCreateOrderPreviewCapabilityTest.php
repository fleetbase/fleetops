<?php

use Fleetbase\FleetOps\Support\Ai\Capabilities\CreateOrderPreviewCapability;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;

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
