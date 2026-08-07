<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderCanceled;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\OrderCanceled as OrderCanceledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderCanceled implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderCanceled $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order    = $event->getModelRecord();
        if ($order->isIntegratedVendorOrder()) {
            $this->notifyIntegratedVendorCanceled($order);
        }

        // Notify driver assigned order was canceled
        if ($order->hasDriverAssigned) {
            /** @var \Fleetbase\Models\Driver */
            $driver = $this->findAssignedDriver($order);

            if ($driver) {
                $this->notifyAssignedDriver($driver, $order);
            }
        }
    }

    protected function notifyIntegratedVendorCanceled($order): void
    {
        $order->facilitator->provider()->callback('onCanceled', $order);
    }

    protected function findAssignedDriver($order): ?Driver
    {
        return Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();
    }

    protected function notifyAssignedDriver(Driver $driver, $order): void
    {
        $driver->notify(new OrderCanceledNotification($order));
    }
}
