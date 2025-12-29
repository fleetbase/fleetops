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
     * Handle the Order "updating" event.
     *
     * This event is fired before the order is persisted to the database.
     * It is used to mutate attributes as part of the same update operation
     * without triggering additional save cycles.
     *
     * @param Order $order The order being updated
     */
    public function updating(Order $order): void
    {
        $this->ensureOrderStarted($order);
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

    /**
     * Detects when an order has just transitioned to the "started" status
     * and initializes start-related fields.
     *
     * This method should be called during the "updating" lifecycle event
     * to ensure that the changes are persisted as part of the same database
     * update and do not trigger additional observer events.
     *
     * An order is considered "started" when:
     * - The "status" attribute is being changed in the current update
     * - The previous status was not "started"
     * - The new status is "started"
     *
     * When these conditions are met, the order's start timestamp and
     * started flag are set if they have not already been initialized.
     *
     * @param Order $order The order being evaluated for a start transition
     */
    protected function ensureOrderStarted(Order $order): void
    {
        if (
            $order->isDirty('status')
            && $order->getOriginal('status') === 'dispatched'
            && $order->status === 'started'
        ) {
            // Only set defaults if not explicitly provided
            if (is_null($order->started_at)) {
                $order->started_at = now();
            }

            if (!$order->started) {
                $order->started = true;
            }
        }
    }
}
