<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * One action, one push to the driver.
 *
 * An order's driver can hear about the same thing by more than one road:
 *
 * - Assigning and dispatching together (an order created with a driver and dispatched at once)
 *   fires both `OrderDriverAssigned` and `OrderDispatched`, which sent "assigned" and
 *   "dispatched" within the same second. The dispatched one says everything the assigned one
 *   does and is the one the driver can act on, so it is the one that goes.
 * - `OrderAssigned` and `OrderDispatched` are sent to the driver directly by their listeners and
 *   again through the notification registry when a company lists the driver (or "Dynamic:
 *   Driver") as a recipient of the same notification.
 *
 * Only the driver's copy is governed here. Customers, console users and every other recipient
 * are left alone. The cache is a convenience: when it is unavailable, everything is sent.
 */
class OrderDriverPushes
{
    /**
     * Seconds within which the same push to the same driver about the same order is a repeat,
     * and a dispatch still counts as "happening together" with an assignment.
     */
    public const WINDOW = 120;

    /**
     * The order is being dispatched to its assigned driver, who will be told so. Called as the
     * dispatch begins, before anything is queued.
     */
    public static function dispatching(Order $order): void
    {
        if ($order->adhoc || !$order->driver_assigned_uuid || !$order->uuid) {
            return;
        }

        try {
            Cache::put(static::key('dispatching', $order->uuid), $order->driver_assigned_uuid, self::WINDOW);
        } catch (\Throwable) {
            // Without the cache the driver hears both, as before.
        }
    }

    /**
     * Whether a dispatch that just went out to this driver already covers telling them the
     * order is theirs. False for a driver assigned after the order was dispatched to someone else.
     */
    public static function dispatchCovers(Order $order, Driver $driver): bool
    {
        try {
            return $order->uuid && Cache::get(static::key('dispatching', $order->uuid)) === $driver->uuid;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True the first time this push is about to go to this driver for this order, false for a
     * repeat within the window. Anyone who isn't a driver is always told.
     */
    public static function firstFor(string $push, Order $order, $notifiable): bool
    {
        if (!$notifiable instanceof Driver || !$order->uuid || !$notifiable->uuid) {
            return true;
        }

        try {
            return (bool) Cache::add(static::key($push, $order->uuid, $notifiable->uuid), 1, self::WINDOW);
        } catch (\Throwable) {
            return true;
        }
    }

    protected static function key(string ...$parts): string
    {
        return 'fleetops:order_driver_push:' . implode(':', $parts);
    }
}
