<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Jobs\ExpireOrderOffer;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Timed offers: one driver at a time, nearest first. An offer moves on when the driver
 * declines or it runs out, and the order is canceled with "No driver found" when nobody is left.
 *
 * Every change of an offer's status is a conditional update, so of two callers closing the
 * same offer (a decline racing its timeout, say) only one moves the order on.
 */
class OrderOffers
{
    public const NO_DRIVER_FOUND = 'No driver found';

    /**
     * Offer a newly dispatched order to its first driver.
     */
    public static function start(Order $order): ?OrderOffer
    {
        if (OrderOffer::where('order_uuid', $order->uuid)->exists()) {
            return static::pending($order);
        }

        return static::offerNext($order);
    }

    /**
     * The order's open offer, if any.
     */
    public static function pending(Order $order): ?OrderOffer
    {
        return OrderOffer::where('order_uuid', $order->uuid)->where('status', OrderOffer::PENDING)->latest('id')->first();
    }

    /**
     * Accept the driver's open offer. False when it isn't theirs or has run out.
     */
    public static function accept(Order $order, Driver $driver): bool
    {
        return OrderOffer::where('order_uuid', $order->uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('status', OrderOffer::PENDING)
            ->where('expires_at', '>', now())
            ->update(['status' => OrderOffer::ACCEPTED, 'responded_at' => now()]) > 0;
    }

    /**
     * The driver turns the offer down, so the next driver gets it. False when they had no open offer.
     */
    public static function decline(Order $order, Driver $driver): bool
    {
        $declined = OrderOffer::where('order_uuid', $order->uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::DECLINED, 'responded_at' => now()]) > 0;

        if ($declined) {
            static::offerNext($order);
        }

        return $declined;
    }

    /**
     * Close an offer that has run out and offer the order to the next driver.
     */
    public static function expire(OrderOffer $offer): bool
    {
        $expired = OrderOffer::where('uuid', $offer->uuid)
            ->where('status', OrderOffer::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => OrderOffer::EXPIRED, 'responded_at' => now()]) > 0;

        if ($expired && ($order = Order::withoutGlobalScopes()->where('uuid', $offer->order_uuid)->first())) {
            static::offerNext($order);
        }

        return $expired;
    }

    /**
     * Close the order's open offer without moving on, for an order that was canceled or assigned.
     */
    public static function withdraw(Order $order): int
    {
        return OrderOffer::where('order_uuid', $order->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::WITHDRAWN, 'responded_at' => now()]);
    }

    /**
     * Offer the order to the nearest driver who hasn't had it yet.
     */
    public static function offerNext(Order $order): ?OrderOffer
    {
        $order->refresh();
        if ($order->started || $order->driver_assigned_uuid || in_array($order->status, ['completed', 'canceled'], true)) {
            return null;
        }

        $pickup = $order->getPickupLocation();
        $driver = Utils::isPoint($pickup) ? static::candidates($order, $pickup)->first() : null;
        if (!$driver) {
            static::noDriverFound($order);

            return null;
        }

        $timeout = $order->config()->offerTimeout();
        $offer   = OrderOffer::create([
            'company_uuid' => $order->company_uuid,
            'order_uuid'   => $order->uuid,
            'driver_uuid'  => $driver->uuid,
            'status'       => OrderOffer::PENDING,
            'distance'     => isset($driver->distance) ? (int) round((float) $driver->distance) : null,
            'expires_at'   => now()->addSeconds($timeout),
        ]);

        try {
            $driver->notify(new OrderPing($order, $offer->distance));
        } catch (\Throwable $exception) {
            Log::warning('Unable to send a ride offer', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
        }

        ExpireOrderOffer::dispatch($offer->uuid)->delay(now()->addSeconds($timeout + 1));

        return $offer;
    }

    /**
     * Online, free drivers of the order's company and class within the order's radius who
     * haven't been offered it, nearest first.
     */
    public static function candidates(Order $order, $pickup): Builder
    {
        $offered = OrderOffer::where('order_uuid', $order->uuid)->pluck('driver_uuid')->all();
        $query   = Driver::query()
            ->withoutGlobalScopes()
            ->where(['status' => 'available', 'online' => 1])
            ->whereNull('deleted_at')
            ->whereNotIn('uuid', $offered)
            ->whereNotNull('location')
            ->whereRaw('ST_Y(location) BETWEEN -90 AND 90 AND ST_X(location) BETWEEN -180 AND 180 AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)')
            ->distanceSphere('location', $pickup, $order->getAdhocPingDistance())
            ->distanceSphereValue('location', $pickup)
            ->orderBy('distance');

        NavigatorReview::excludeFrom($query);

        return DriverMatching::forOrder($query, $order);
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
