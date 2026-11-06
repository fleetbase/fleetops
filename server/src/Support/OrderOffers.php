<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderOffer;
use Illuminate\Support\Carbon;

/**
 * The record of who an adhoc order was offered to and how each driver answered, shared by every
 * adhoc dispatch strategy.
 *
 * Every change of an offer's status is a conditional update, so of two callers closing the same
 * offer (a decline racing its timeout, say) only one of them changes it.
 */
class OrderOffers
{
    public const NO_DRIVER_FOUND = 'No driver found';

    /**
     * Offer the order to the driver, or keep the offer they already have open.
     */
    public static function record(Order $order, Driver $driver, ?Carbon $expiresAt = null): OrderOffer
    {
        return OrderOffer::firstOrCreate(
            ['order_uuid' => $order->uuid, 'driver_uuid' => $driver->uuid, 'status' => OrderOffer::PENDING],
            [
                'company_uuid' => $order->company_uuid,
                'distance'     => isset($driver->distance) ? (int) round((float) $driver->distance) : null,
                'expires_at'   => $expiresAt,
            ]
        );
    }

    /**
     * The order's newest open offer, if any.
     */
    public static function pending(Order $order): ?OrderOffer
    {
        return OrderOffer::where('order_uuid', $order->uuid)->where('status', OrderOffer::PENDING)->latest('id')->first();
    }

    /**
     * Drivers the order was offered to, whatever they answered.
     */
    public static function offeredDrivers(Order $order): array
    {
        return OrderOffer::where('order_uuid', $order->uuid)->pluck('driver_uuid')->all();
    }

    /**
     * Drivers who turned the order down.
     */
    public static function declinedDrivers(Order $order): array
    {
        return OrderOffer::where('order_uuid', $order->uuid)->where('status', OrderOffer::DECLINED)->pluck('driver_uuid')->all();
    }

    /**
     * Accept the driver's open offer, if it hasn't run out. False when they have none.
     */
    public static function acceptPending(Order $order, Driver $driver): bool
    {
        return OrderOffer::where('order_uuid', $order->uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('status', OrderOffer::PENDING)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->update(['status' => OrderOffer::ACCEPTED, 'responded_at' => now()]) > 0;
    }

    /**
     * Turn down the driver's open offer. False when they have none.
     */
    public static function declinePending(Order $order, Driver $driver): bool
    {
        return OrderOffer::where('order_uuid', $order->uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::DECLINED, 'responded_at' => now()]) > 0;
    }

    /**
     * Record that the driver doesn't want the order, offered to them or not, so it isn't offered to them again.
     */
    public static function recordDecline(Order $order, Driver $driver): void
    {
        if (!static::declinePending($order, $driver) && !in_array($driver->uuid, static::declinedDrivers($order), true)) {
            OrderOffer::create([
                'company_uuid' => $order->company_uuid,
                'order_uuid'   => $order->uuid,
                'driver_uuid'  => $driver->uuid,
                'status'       => OrderOffer::DECLINED,
                'responded_at' => now(),
            ]);
        }
    }

    /**
     * The driver got the order: their offer is accepted and everyone else's is withdrawn.
     */
    public static function claimed(Order $order, Driver $driver): void
    {
        OrderOffer::where('order_uuid', $order->uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::ACCEPTED, 'responded_at' => now()]);

        static::withdraw($order);
    }

    /**
     * Close the order's open offers, for an order that was taken or canceled.
     */
    public static function withdraw(Order $order): int
    {
        return OrderOffer::where('order_uuid', $order->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::WITHDRAWN, 'responded_at' => now()]);
    }

    /**
     * Nobody is left to offer the order to, so cancel it and say why.
     */
    public static function noDriverFound(Order $order): void
    {
        $order->updateMeta('dispatch_outcome', 'no_driver_found');
        $order->cancelWithReason(self::NO_DRIVER_FOUND);
    }
}
