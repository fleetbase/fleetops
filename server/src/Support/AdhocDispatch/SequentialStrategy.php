<?php

namespace Fleetbase\FleetOps\Support\AdhocDispatch;

use Fleetbase\FleetOps\Contracts\AdhocDispatchStrategy;
use Fleetbase\FleetOps\Jobs\ExpireOrderOffer;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\DriverMatching;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Log;

/**
 * Offer the order to one driver at a time, nearest first, for the offer timeout. The next driver
 * gets it when one declines or the time runs out, and the order is canceled as "No driver found"
 * when nobody is left. Only the driver with the open offer may accept.
 */
class SequentialStrategy implements AdhocDispatchStrategy
{
    public function name(): string
    {
        return AdhocDispatch::SEQUENTIAL;
    }

    public function dispatch(Order $order): void
    {
        if (!OrderOffer::where('order_uuid', $order->uuid)->exists()) {
            $this->offerNext($order);
        }
    }

    /**
     * Move on from an offer whose expiry job was lost, or start offers that never began.
     */
    public function redispatch(Order $order, bool $testing = false): int
    {
        $pending = OrderOffers::pending($order);
        if ($pending && $pending->expires_at?->isPast()) {
            return $this->expire($pending) ? 1 : 0;
        }

        if (!$pending && !OrderOffer::where('order_uuid', $order->uuid)->exists()) {
            return $this->offerNext($order) ? 1 : 0;
        }

        return 0;
    }

    public function accept(Order $order, Driver $driver): bool
    {
        return OrderOffers::acceptPending($order, $driver);
    }

    public function decline(Order $order, Driver $driver): bool
    {
        if (!OrderOffers::declinePending($order, $driver)) {
            return false;
        }

        $this->offerNext($order);

        return true;
    }

    /**
     * Close an offer that has run out and offer the order to the next driver.
     */
    public function expire(OrderOffer $offer): bool
    {
        $expired = OrderOffer::where('uuid', $offer->uuid)
            ->where('status', OrderOffer::PENDING)
            ->where('expires_at', '<=', now())
            ->update(['status' => OrderOffer::EXPIRED, 'responded_at' => now()]) > 0;

        if ($expired && ($order = Order::withoutGlobalScopes()->where('uuid', $offer->order_uuid)->first())) {
            $this->offerNext($order);
        }

        return $expired;
    }

    /**
     * Offer the order to the nearest driver who hasn't had it yet.
     */
    public function offerNext(Order $order): ?OrderOffer
    {
        $order->refresh();
        if ($order->started || $order->driver_assigned_uuid || in_array($order->status, ['completed', 'canceled'], true)) {
            return null;
        }

        $pickup = $order->getPickupLocation();
        $driver = Utils::isPoint($pickup)
            ? DriverMatching::nearby($order, $pickup)->whereNotIn('uuid', OrderOffers::offeredDrivers($order))->orderBy('distance')->first()
            : null;
        if (!$driver) {
            OrderOffers::noDriverFound($order);

            return null;
        }

        $timeout = AdhocDispatch::offerTimeout($order);
        $offer   = OrderOffers::record($order, $driver, now()->addSeconds($timeout));

        try {
            $driver->notify(new OrderPing($order, $offer->distance));
        } catch (\Throwable $exception) {
            Log::warning('Unable to send an order offer', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
        }

        ExpireOrderOffer::dispatch($offer->uuid)->delay(now()->addSeconds($timeout + 1));

        return $offer;
    }
}
