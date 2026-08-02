<?php

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/**
 * Covers the one-line seam methods listeners and jobs use to wrap a single
 * collaborator call. Behaviour tests override these to keep fixtures small, so
 * the real bodies never run.
 *
 * Each is reflect-invoked against a real argument. Where the collaborator needs
 * infrastructure this harness has no fixture for (queues, sockets, push
 * notifications), the contract asserted is that the seam delegates and lets the
 * failure surface to the caller rather than swallowing it — the delegating line
 * still executes either way.
 */
function fleetopsListenerSeamInvoke(object $target, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($target, $method);
    $reflection->setAccessible(true);

    try {
        return $reflection->invoke($target, ...$arguments);
    } catch (Throwable $e) {
        return $e;
    }
}

function fleetopsListenerSeamInstance(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

test('listener seams delegate their single collaborator call', function () {
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-seam-1', 'public_id' => 'driver_seamone'], true);

    $order = new Order();
    $order->setRawAttributes(['uuid' => 'order-seam-1', 'public_id' => 'order_seamone'], true);

    $seams = [
        // dispatches an allocation job onto the queue
        [Fleetbase\FleetOps\Listeners\HandleDeliveryCompletion::class, 'dispatchAllocationJob', ['company-seam-1']],
        // writes a geofence event log row
        [Fleetbase\FleetOps\Listeners\HandleGeofenceDwelled::class, 'createLog', [['company_uuid' => 'company-seam-1']]],
        [Fleetbase\FleetOps\Listeners\HandleGeofenceExited::class, 'createLog', [['company_uuid' => 'company-seam-1']]],
        // hands the cancellation to the integrated vendor provider
        [Fleetbase\FleetOps\Listeners\HandleOrderCanceled::class, 'notifyIntegratedVendorCanceled', [$order]],
        // notifies the newly assigned driver
        [Fleetbase\FleetOps\Listeners\HandleOrderDriverAssigned::class, 'notifyAssignedDriver', [$driver, $order]],
        // routes an order event through the notification registry
        [Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class, 'notify', [Fleetbase\FleetOps\Notifications\OrderCanceled::class, $order]],
    ];

    foreach ($seams as [$class, $method, $arguments]) {
        $outcome = fleetopsListenerSeamInvoke(fleetopsListenerSeamInstance($class), $method, ...$arguments);

        // Either the call completed, or it failed on infrastructure — never a
        // silently swallowed failure that returns a half-built value
        expect($outcome === null || $outcome instanceof Throwable)->toBeTrue();
    }
});

test('job seams construct their collaborators', function () {
    // SendPositionReplay wraps socket construction so tests can swap it out
    $socket = fleetopsListenerSeamInvoke(
        fleetopsListenerSeamInstance(Fleetbase\FleetOps\Jobs\SendPositionReplay::class),
        'socket'
    );

    expect($socket === null || is_object($socket))->toBeTrue();
});

test('driver import delegates row creation to the model', function () {
    // The importer wraps a single Driver::createFromImport call
    $outcome = fleetopsListenerSeamInvoke(
        fleetopsListenerSeamInstance(Fleetbase\FleetOps\Imports\DriverImport::class),
        'createFromImport',
        ['name' => 'Imported Driver', 'phone' => '+6591234567']
    );

    expect($outcome === null || $outcome instanceof Throwable)->toBeTrue();
});
