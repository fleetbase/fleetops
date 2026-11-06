<?php

namespace Fleetbase\FleetOps\Support\Rides;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\DriverRating;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Database\QueryException;

/**
 * A customer's stars for the driver of a finished order, one rating per order. The driver's
 * average and count (`rating_average`, `rating_count`) are worked out again on each new rating.
 */
class DriverRatings
{
    /**
     * Rate the order's driver. False when the order already has a rating.
     */
    public static function rate(Order $order, int $stars, ?string $comment = null): bool
    {
        if (DriverRating::where('order_uuid', $order->uuid)->exists()) {
            return false;
        }

        try {
            DriverRating::create([
                'company_uuid'  => $order->company_uuid,
                'order_uuid'    => $order->uuid,
                'driver_uuid'   => $order->driver_assigned_uuid,
                'customer_uuid' => $order->customer_uuid,
                'customer_type' => $order->customer_type,
                'stars'         => max(1, min(5, $stars)),
                'comment'       => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            ]);
        } catch (QueryException) {
            // Another request rated the order first; `order_uuid` is unique.
            return false;
        }

        static::refresh($order->driver_assigned_uuid);

        return true;
    }

    /**
     * Work out the driver's average and count again from their ratings.
     */
    public static function refresh(?string $driverUuid): void
    {
        $ratings = DriverRating::where('driver_uuid', $driverUuid)->whereNull('deleted_at');
        $count   = (clone $ratings)->count();

        Driver::withoutGlobalScopes()->where('uuid', $driverUuid)->update([
            'rating_average' => $count ? round((float) (clone $ratings)->avg('stars'), 2) : null,
            'rating_count'   => $count,
        ]);
    }
}
