<?php

namespace Fleetbase\FleetOps\Support\Rides;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\RideUpdate;
use Fleetbase\FleetOps\Support\OrderOffers;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Decides which ride changes reach the rider as a push.
 */
class RideUpdates
{
    /**
     * Metres from the pickup at which the rider is told the driver is about to arrive.
     */
    public const ARRIVING_DISTANCE = 400;

    /**
     * A ride's status changed: tell the rider when it's a step they need to know about.
     */
    public static function statusChanged(Order $order): bool
    {
        if (!static::isRide($order) || !$order->wasChanged('status')) {
            return false;
        }

        $kind = OrderOffers::outcome($order) === OrderOffers::NO_DRIVER_FOUND_OUTCOME ? 'no_driver_found' : (string) $order->status;

        return RideUpdate::knows($kind) && static::send($order, $kind);
    }

    /**
     * The driver of a ride reported a position: tell the rider once when the car is close.
     */
    public static function driverMoved(Driver $driver, ?Order $order): bool
    {
        if (!$order || !static::isRide($order) || $order->status !== 'started') {
            return false;
        }

        $pickup = $order->getPickupLocation();
        if (!Utils::isPoint($pickup) || !Utils::isPoint($driver->location)) {
            return false;
        }

        $distance = Utils::vincentyGreatCircleDistance($driver->location, $pickup);
        if ($distance > self::ARRIVING_DISTANCE || !Cache::add('fleetops:ride:arriving:' . $order->uuid, 1, now()->addHours(6))) {
            return false;
        }

        return static::send($order, 'arriving');
    }

    public static function isRide(Order $order): bool
    {
        return $order->type === 'ride';
    }

    protected static function send(Order $order, string $kind): bool
    {
        $rider = $order->customer;
        if (!$rider || !method_exists($rider, 'notify')) {
            return false;
        }

        try {
            $rider->notify(new RideUpdate($order, $kind));
        } catch (\Throwable $exception) {
            Log::warning('Unable to send a ride update', ['order' => $order->public_id, 'error' => $exception->getMessage()]);

            return false;
        }

        return true;
    }
}
