<?php

use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType;

/**
 * Covers the LalamoveServiceType value object: dynamic property hydration,
 * restriction fallbacks through __get, the instance-call `all` proxy, and
 * the static find variants by key and callback.
 */
test('service type exposes properties and restriction fallbacks', function () {
    $serviceType = new LalamoveServiceType([
        'key'          => 'CAR',
        'description'  => 'Car',
        'restrictions' => ['length' => '70cm', 'width' => '50cm', 'height' => '50cm', 'weight' => '20kg'],
    ]);

    expect($serviceType->getKey())->toBe('CAR')
        ->and($serviceType->description)->toBe('Car')
        // Restriction keys resolve through the __get fallback
        ->and($serviceType->weight)->toBe('20kg')
        ->and($serviceType->LENGTH)->toBe('70cm')
        ->and($serviceType->unknown_property)->toBeNull();
});

test('all returns hydrated service types statically and via instance calls', function () {
    $all = LalamoveServiceType::all();

    expect($all->count())->toBeGreaterThanOrEqual(4)
        ->and($all->first())->toBeInstanceOf(LalamoveServiceType::class)
        ->and($all->map(fn ($type) => $type->key)->all())->toContain('MOTORCYCLE', 'CAR', 'VAN');

    // The instance __call proxy mirrors the static all()
    $instance = new LalamoveServiceType(['key' => 'CAR']);
    $viaCall  = $instance->all();
    expect($viaCall->count())->toBe($all->count());

    // Unknown instance calls return null
    expect($instance->somethingUnknown())->toBeNull();
});

test('find locates service types by key string and callback', function () {
    $van = LalamoveServiceType::find('van');
    expect($van)->toBeInstanceOf(LalamoveServiceType::class)
        ->and($van->key)->toBe('VAN');

    $byCallback = LalamoveServiceType::find(fn ($type) => $type->key === 'SUV');
    expect($byCallback->key)->toBe('SUV');

    expect(LalamoveServiceType::find('hovercraft'))->toBeNull()
        ->and(LalamoveServiceType::find(42))->toBeNull();
});
