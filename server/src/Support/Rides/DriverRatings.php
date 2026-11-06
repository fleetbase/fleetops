<?php

namespace Fleetbase\FleetOps\Support\Rides;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;

/**
 * A rider's stars for the driver of a finished ride. The rating is kept on the order, and the
 * driver's average (`meta.rating`) is worked out again from their rated orders each time.
 */
class DriverRatings
{
    /**
     * Rate the order's driver. False when the ride already has a rating.
     */
    public static function rate(Order $order, int $stars, ?string $comment = null): bool
    {
        if (data_get($order->meta, 'rating')) {
            return false;
        }

        $order->updateMeta('rating', array_filter([
            'stars'    => max(1, min(5, $stars)),
            'comment'  => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'rated_at' => now()->toISOString(),
        ], fn ($value) => $value !== null));

        $driver = Driver::withoutGlobalScopes()->where('uuid', $order->driver_assigned_uuid)->first();
        if ($driver) {
            $driver->forceFill(['meta' => array_merge((array) $driver->meta, ['rating' => static::summary($driver)])])->saveQuietly();
        }

        return true;
    }

    /**
     * The driver's average stars and how many rides it comes from.
     */
    public static function summary(Driver $driver): array
    {
        $stars = Order::withoutGlobalScopes()
            ->where('driver_assigned_uuid', $driver->uuid)
            ->whereNull('deleted_at')
            ->whereNotNull('meta')
            ->pluck('meta')
            ->map(fn ($meta) => data_get(is_string($meta) ? json_decode($meta, true) : $meta, 'rating.stars'))
            ->filter(fn ($value) => is_numeric($value));

        return ['average' => $stars->isEmpty() ? null : round($stars->avg(), 2), 'count' => $stars->count()];
    }

    /**
     * The driver's average, or null before their first rating.
     */
    public static function average(mixed $driver): ?float
    {
        $average = data_get($driver, 'meta.rating.average');

        return is_numeric($average) ? (float) $average : null;
    }
}
