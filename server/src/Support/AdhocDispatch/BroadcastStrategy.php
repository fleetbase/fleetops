<?php

namespace Fleetbase\FleetOps\Support\AdhocDispatch;

use Fleetbase\FleetOps\Contracts\AdhocDispatchStrategy;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\DriverMatching;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Offer the order to every nearby driver at once. The first to accept gets it. A driver who
 * declines isn't offered it again, and `fleetops:dispatch-adhoc` offers it again to the rest.
 */
class BroadcastStrategy implements AdhocDispatchStrategy
{
    public function name(): string
    {
        return AdhocDispatch::BROADCAST;
    }

    public function dispatch(Order $order): void
    {
        $this->offer($order);
    }

    public function redispatch(Order $order, bool $testing = false): int
    {
        $offered = $this->offer($order, $testing)->count();
        if ($offered) {
            // Restart the re-offer interval without dispatching again, which would offer it twice.
            $order->forceFill(['dispatched_at' => Carbon::now()])->saveQuietly();
        }

        return $offered;
    }

    public function accept(Order $order, Driver $driver): bool
    {
        return true;
    }

    public function decline(Order $order, Driver $driver): bool
    {
        OrderOffers::recordDecline($order, $driver);

        return true;
    }

    /**
     * Offer the order to every nearby driver who hasn't declined it. Returns those drivers.
     */
    protected function offer(Order $order, bool $testing = false): Collection
    {
        $pickup = $order->getPickupLocation();
        if (!Utils::isPoint($pickup)) {
            return collect();
        }

        $drivers = DriverMatching::nearby($order, $pickup, $testing)->whereNotIn('uuid', OrderOffers::declinedDrivers($order))->get();
        foreach ($drivers as $driver) {
            OrderOffers::record($order, $driver);
            $this->ping($order, $driver);
        }

        return $drivers;
    }

    protected function ping(Order $order, Driver $driver): void
    {
        try {
            $driver->notify(new OrderPing($order, $driver->distance ?? null));
        } catch (\Throwable) {
            // One driver's failed push doesn't stop the others being offered the order.
        }
    }
}
