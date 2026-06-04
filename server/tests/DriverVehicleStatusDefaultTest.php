<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;

test('driver and vehicle statuses default to available', function () {
    expect((new Driver())->status)
        ->toBe('available')
        ->and((new Vehicle())->status)
        ->toBe('available');
});

test('legacy active and null driver and vehicle statuses normalize to available', function () {
    $driver         = new Driver();
    $driver->status = 'active';
    expect($driver->status)->toBe('available');

    $driver->status = null;
    expect($driver->status)->toBe('available');

    $vehicle         = new Vehicle();
    $vehicle->status = 'active';
    expect($vehicle->status)->toBe('available');

    $vehicle->status = null;
    expect($vehicle->status)->toBe('available');
});

test('dispatch driver availability queries use available status', function () {
    $listener = file_get_contents(dirname(__DIR__) . '/src/Listeners/HandleOrderDispatched.php');
    $order    = file_get_contents(dirname(__DIR__) . '/src/Models/Order.php');

    expect($listener)
        ->toContain("'status' => 'available'")
        ->not->toContain("Driver::where(['status' => 'active'")
        ->and($order)
        ->toContain("'status' => 'available'")
        ->not->toContain("Driver::where(['status' => 'active'");
});
