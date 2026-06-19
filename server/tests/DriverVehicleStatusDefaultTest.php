<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Vehicle;

test('driver vehicle and equipment statuses default to available', function () {
    expect((new Driver())->status)
        ->toBe('available')
        ->and((new Vehicle())->status)
        ->toBe('available')
        ->and((new Equipment())->status)
        ->toBe('available');
});

test('legacy active and null driver vehicle and equipment statuses normalize to available', function () {
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

    $equipment         = new Equipment();
    $equipment->status = 'active';
    expect($equipment->status)->toBe('available');

    $equipment->status = null;
    expect($equipment->status)->toBe('available');
});

test('equipment preserves explicit non default status values', function () {
    $equipment         = new Equipment();
    $equipment->status = 'maintenance';

    expect($equipment->status)->toBe('maintenance');
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
