<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\LiveCacheService;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function created(Order $order)
    {
        $this->invalidateCache();
    }

    /**
     * Handle the Order "updated" event.
     *
     * @return void
     */
    public function updated(Order $order)
    {
        $order->setDriverLocationAsPickup();

        if ($order->wasChanged('driver_assigned_uuid')) {
            $order->notifyDriverAssigned();
        }

        $this->invalidateCache();
    }

    /**
     * Handle the Order "deleted" event.
     *
     * @return void
     */
    public function deleted(Order $order)
    {
        if ($order->isIntegratedVendorOrder()) {
            $order->facilitator->provider()->callback('onDeleted', $order);
        }

        $this->invalidateCache();
    }

    /**
     * Invalidate relevant cache tags for live endpoints.
     *
     * @return void
     */
    protected function invalidateCache(): void
    {
        LiveCacheService::invalidateMultiple(['orders', 'routes', 'coordinates']);
    }
}
