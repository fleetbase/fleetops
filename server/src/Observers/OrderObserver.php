<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Illuminate\Support\Facades\Cache;

class OrderObserver
{
    /**
     * Handle the Order "created" event.
     *
     * @return void
     */
    public function created(Order $order)
    {
        $this->invalidateCache($order);
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

        $this->invalidateCache($order);
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

        $this->invalidateCache($order);
    }

    /**
     * Invalidate relevant cache tags for live endpoints.
     *
     * @param Order|null $order Optional order to invalidate specific tracker cache
     */
    protected function invalidateCache(?Order $order = null): void
    {
        LiveCacheService::invalidateMultiple(['orders', 'routes', 'coordinates']);

        // Invalidate order-specific tracker cache if order is provided
        if ($order && $order->uuid) {
            Cache::forget("order:{$order->uuid}:tracker");
        }
    }
}
