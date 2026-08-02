<?php

use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderDescriptor;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;

/**
 * Covers registry resolution guards: unknown keys, descriptors naming a
 * driver class that does not exist, and drivers that do not implement the
 * provider contract are each rejected with a distinct message.
 */
test('registry resolution rejects unusable provider descriptors', function () {
    $registry = new FuelProviderRegistry();

    // Nothing registered under that key
    expect(fn () => $registry->resolve('nonexistent'))
        ->toThrow(InvalidArgumentException::class, "Fuel provider 'nonexistent' is not registered.");

    // Registered, but the named driver class cannot be autoloaded
    $registry->register(new FuelProviderDescriptor([
        'key'          => 'ghost',
        'name'         => 'Ghost Provider',
        'driver_class' => 'Fleetbase\\FleetOps\\Support\\FuelProviders\\Drivers\\NoSuchDriver',
    ]));
    expect(fn () => $registry->resolve('ghost'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');

    // Registered and loadable, but not a FuelProvider implementation
    $registry->register(new FuelProviderDescriptor([
        'key'          => 'wrong-contract',
        'name'         => 'Wrong Contract',
        'driver_class' => stdClass::class,
    ]));
    expect(fn () => $registry->resolve('wrong-contract'))
        ->toThrow(InvalidArgumentException::class, 'must implement FuelProvider');
});

test('resolved integrated vendors expose config keys through the magic getter', function () {
    // ResolvedIntegratedVendor shares a file with IntegratedVendors, so it is
    // not PSR-4 autoloadable on its own
    class_exists(Fleetbase\FleetOps\Support\IntegratedVendors::class);

    $vendor = new Fleetbase\FleetOps\Support\ResolvedIntegratedVendor([
        'code' => 'lalamove',
        'name' => 'Lalamove',
    ]);

    // Config keys resolve case-insensitively; anything else is null
    expect($vendor->NAME)->toBe('Lalamove')
        ->and($vendor->code)->toBe('lalamove')
        ->and($vendor->definitelyNotAVendorKey)->toBeNull();
});
