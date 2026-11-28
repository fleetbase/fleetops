<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderDriverAssigned;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Support\OrderDriverPushes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderDriverAssigned implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDriverAssigned $event)
    {
        /** @var Order $order */
        $order = $event->getModelRecord();

        // halt if unable to resolve order record from event
        if (!$order instanceof Order) {
            return;
        }

        /** @var Driver */
        $driver = $this->findAssignedDriver($order);
        $order->setRelation('driverAssigned', $driver);

        // notify driver order has been assigned - only if order is not adhoc
        if ($driver && $order->adhoc === false) {
            // Assigned and dispatched together: the driver gets the one "dispatched" push,
            // which is the one they can act on, rather than two notifications for one action.
            if ($this->dispatchAlreadyTellsDriver($order, $driver)) {
                return;
            }

            $this->notifyAssignedDriver($driver, $order);
        }
    }

    protected function findAssignedDriver(Order $order): ?Driver
    {
        return Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();
    }

    protected function dispatchAlreadyTellsDriver(Order $order, Driver $driver): bool
    {
        return OrderDriverPushes::dispatchCovers($order, $driver);
    }

    protected function notifyAssignedDriver(Driver $driver, Order $order): void
    {
        $driver->notify(new OrderAssigned($order));
    }
}
