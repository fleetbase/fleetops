<?php

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\FleetOps\Support\TrackingPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Covers how the order observer follows driver assignment: a replaced driver is
 * told they were unassigned, the new driver is notified exactly once (and not at
 * all for a silent assignment), and saving an assignment starts the driver offer.
 */
class FleetOpsOrderAssignmentObserverOrder extends Order
{
    public array $events       = [];
    public array $originalData = [];
    public bool $driverChanged = true;
    public bool $driverDirty   = false;

    public function getOriginal($key = null, $default = null): mixed
    {
        return $key === null ? $this->originalData : ($this->originalData[$key] ?? $default);
    }

    public function wasChanged($attributes = null): bool
    {
        return $attributes === 'driver_assigned_uuid' && $this->driverChanged;
    }

    public function isDirty($attributes = null): bool
    {
        return $attributes === 'driver_assigned_uuid' && $this->driverDirty;
    }

    public function setDriverLocationAsPickup($force = false)
    {
    }

    public function notifyDriverAssigned()
    {
        $this->events[] = 'assigned:' . $this->driver_assigned_uuid;
    }

    public function notifyDriverUnassigned($driver): bool
    {
        $this->events[] = 'unassigned:' . $driver;

        return true;
    }

    public function syncOfferWithAssignment(): Order
    {
        $this->events[] = 'offer';

        return $this;
    }
}

beforeEach(function () {
    Cache::swap(new Repository(new ArrayStore()));
    session(['company' => 'company-uuid']);

    // public tracking is published elsewhere; it is not what these tests cover
    app()->instance(TrackingPublisher::class, new class extends TrackingPublisher {
        public function orderChanged(Order $order): bool
        {
            return false;
        }
    });
});

test('reassigning an order tells the replaced driver and notifies the new one once', function () {
    $order = new FleetOpsOrderAssignmentObserverOrder();
    $order->setRawAttributes(['uuid' => 'order-uuid', 'driver_assigned_uuid' => 'driver-new'], true);
    $order->originalData = ['driver_assigned_uuid' => 'driver-old'];

    (new OrderObserver())->updated($order);

    expect($order->events)->toBe(['unassigned:driver-old', 'assigned:driver-new']);
});

test('unassigning tells the driver and a silent assignment sends no assignment notification', function () {
    $unassigned = new FleetOpsOrderAssignmentObserverOrder();
    $unassigned->setRawAttributes(['uuid' => 'order-uuid', 'driver_assigned_uuid' => null], true);
    $unassigned->originalData = ['driver_assigned_uuid' => 'driver-old'];

    (new OrderObserver())->updated($unassigned);

    $silent = new FleetOpsOrderAssignmentObserverOrder();
    $silent->setRawAttributes(['uuid' => 'order-uuid', 'driver_assigned_uuid' => 'driver-new'], true);
    $silent->silentDriverAssignment = true;

    (new OrderObserver())->updated($silent);

    expect($unassigned->events)->toBe(['unassigned:driver-old', 'assigned:'])
        ->and($silent->events)->toBe([]);
});

test('saving a driver assignment starts the offer in the same write', function () {
    $order              = new FleetOpsOrderAssignmentObserverOrder();
    $order->driverDirty = true;

    (new OrderObserver())->saving($order);

    $unchanged              = new FleetOpsOrderAssignmentObserverOrder();
    $unchanged->driverDirty = false;

    (new OrderObserver())->saving($unchanged);

    expect($order->events)->toBe(['offer'])
        ->and($unchanged->events)->toBe([]);
});

test('assign driver relies on the observer instead of notifying before the save', function () {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Models/Order.php');
    preg_match('/public function assignDriver\(.*?\n    }\n/s', $source, $matches);

    expect($matches[0] ?? '')->not->toContain('notifyDriverAssigned()')
        ->toContain('$this->silentDriverAssignment = (bool) $silent;');
});
