<?php

use Fleetbase\FleetOps\Flow\FlowResource;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveDeliveryStop;
use Fleetbase\FleetOps\Integrations\Lalamove\LalamoveServiceType;

/**
 * Covers the magic accessors that fall back to a real declared property when a
 * key is not present in the backing attribute array. That fallback only fires
 * for keys the object actually declares, which ordinary attribute access never
 * reaches.
 */
test('flow resource lookups prefer attributes then declared properties', function () {
    $resource = new FlowResource(['code' => 'dispatched', 'status' => 'Dispatched']);

    // Keys present in the attribute array win
    expect($resource->get('code'))->toBe('dispatched')
        ->and($resource->get('status'))->toBe('Dispatched');

    // A declared property is returned when the key is not an attribute
    $resource->extra = 'declared-value';
    expect($resource->get('extra'))->toBe('declared-value');

    // Anything else falls through to the default
    expect($resource->get('missing', 'fallback'))->toBe('fallback');
});

test('lalamove value objects resolve properties case insensitively', function () {
    // Both __get implementations strtolower() the key before looking it up, so
    // they only fire for differently-cased access — a property reached with its
    // exact name is accessible directly and never enters __get at all
    $serviceType = new LalamoveServiceType([
        'key'          => 'MOTORCYCLE',
        'description'  => 'Motorcycle',
        'restrictions' => ['length' => '40cm', 'width' => '40cm', 'height' => '40cm', 'weight' => '10kg'],
    ]);

    expect($serviceType->Description)->toBe('Motorcycle')
        ->and($serviceType->KEY)->toBe('MOTORCYCLE');

    // Restriction keys are not properties, so they resolve via the restrictions array
    expect($serviceType->weight)->toBe('10kg')
        ->and($serviceType->nonexistent)->toBeNull();

    // all() is a declared public static, so this resolves directly and never
    // enters __call — which is why __call's own `all` branch is unreachable,
    // the same shadowing as Lalamove::__callStatic's `instance` branch
    expect($serviceType->all())->not->toBeNull();

    $stop = new LalamoveDeliveryStop(1.3, 103.8, '1 Marina Boulevard');
    expect($stop->Address)->toBe('1 Marina Boulevard')
        ->and($stop->LATITUDE)->toBe(1.3)
        ->and($stop->unknownKey)->toBeNull();
});
