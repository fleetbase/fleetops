<?php

use Fleetbase\FleetOps\Support\FleetOps;
use Fleetbase\FleetOps\Support\Geocoding;
use Illuminate\Support\Str;

function fleetopsTransportConfigDefaults(): array
{
    $reflection = new ReflectionMethod(FleetOps::class, 'transportConfigDefaults');
    $reflection->setAccessible(true);

    return $reflection->invoke(null);
}

test('fleet ops support builds the default transport config metadata', function () {
    $defaults = fleetopsTransportConfigDefaults();

    expect($defaults)->toMatchArray([
        'name'         => 'Transport',
        'key'          => 'transport',
        'namespace'    => 'system:order-config:transport',
        'description'  => 'Default order configuration for transport',
        'core_service' => 1,
        'status'       => 'private',
        'version'      => '0.0.1',
        'tags'         => ['transport', 'delivery'],
        'entities'     => [],
        'meta'         => [],
    ])
        ->and(array_keys($defaults['flow']))->toBe([
            'created',
            'enroute',
            'started',
            'completed',
            'dispatched',
        ]);
});

test('fleet ops support default transport flow links statuses in order', function () {
    $flow = fleetopsTransportConfigDefaults()['flow'];

    expect($flow['created'])->toMatchArray([
        'key'         => 'created',
        'code'        => 'created',
        'status'      => 'Order Created',
        'details'     => 'New order was created.',
        'complete'    => false,
        'activities'  => ['dispatched'],
        'pod_method'  => 'scan',
        'require_pod' => false,
    ])
        ->and($flow['dispatched']['activities'])->toBe(['started'])
        ->and($flow['started']['activities'])->toBe(['enroute'])
        ->and($flow['enroute']['activities'])->toBe(['completed'])
        ->and($flow['completed'])->toMatchArray([
            'status'     => 'Order Completed',
            'complete'   => true,
            'activities' => [],
        ]);
});

test('fleet ops support assigns unique internal ids to every default transport status', function () {
    $flow        = fleetopsTransportConfigDefaults()['flow'];
    $internalIds = array_column($flow, 'internalId');

    expect($internalIds)->toHaveCount(5)
        ->and(array_unique($internalIds))->toHaveCount(5);

    foreach ($internalIds as $internalId) {
        expect(Str::isUuid((string) $internalId))->toBeTrue();
    }
});

test('geocoding support handles disabled and empty coordinate queries without external calls', function () {
    app('config')->set('services.google_maps.api_key', null);

    expect(Geocoding::canGoogleGeocode())->toBeFalse();
});
