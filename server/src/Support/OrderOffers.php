<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Events\OrderOfferClosed;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderOffer;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;
use Fleetbase\FleetOps\Support\OrderConfig\Policies\AdhocDispatchPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
     * What `outcome()` calls an order canceled because nobody took it.
     */
    public const NO_DRIVER_FOUND_OUTCOME = 'no_driver_found';

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
        $open      = OrderOffer::where('order_uuid', $order->uuid)->where('status', OrderOffer::PENDING)->pluck('driver_uuid')->all();
        $withdrawn = OrderOffer::where('order_uuid', $order->uuid)
            ->where('status', OrderOffer::PENDING)
            ->update(['status' => OrderOffer::WITHDRAWN, 'responded_at' => now()]);

        if ($withdrawn) {
            static::announceClosed($order, $open, OrderOffer::WITHDRAWN);
        }

        return $withdrawn;
    }

    /**
     * Tell each driver their offer for the order closed, so their app takes it off screen.
     */
    public static function announceClosed(Order $order, array $driverUuids, string $status): void
    {
        $publicIds = Driver::withoutGlobalScopes()->whereIn('uuid', $driverUuids)->pluck('public_id', 'uuid');

        foreach ($driverUuids as $driverUuid) {
            try {
                event(new OrderOfferClosed($order->public_id, $status, $publicIds[$driverUuid] ?? null, $driverUuid));
            } catch (\Throwable $exception) {
                Log::warning('Unable to broadcast order.offer_closed', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * Nobody is left to offer the order to. The config's `dispatch.offer_exhausted` triggers run,
     * and the order is canceled as "No driver found" when its `dispatch.adhoc` policy says so.
     * Otherwise it stays open for the operator to assign, and later runs that find nobody again
     * do nothing more.
     */
    public static function exhausted(Order $order): void
    {
        // An order kept open is offered again on each scheduled run; its config hears that
        // nobody accepted only the first time.
        if ($order->getMeta('dispatch_outcome') === self::NO_DRIVER_FOUND_OUTCOME) {
            return;
        }

        $order->updateMeta('dispatch_outcome', self::NO_DRIVER_FOUND_OUTCOME);
        OrderConfigTriggers::fire($order, OrderConfigTriggers::DISPATCH_OFFER_EXHAUSTED);

        if ($order->currentConfig()?->policy('dispatch.adhoc.on_exhausted') === AdhocDispatchPolicy::CANCEL) {
            $order->cancelWithReason(self::NO_DRIVER_FOUND);
        }
    }

    /**
     * How offering an adhoc order ended: `no_driver_found` when every driver it was offered to
     * declined or let the offer run out and no driver has been assigned since. Null otherwise,
     * including when someone canceled it while an offer was still open, which withdraws that offer.
     */
    public static function outcome(Order $order): ?string
    {
        if (!$order->adhoc || $order->driver_assigned_uuid) {
            return null;
        }

        if ($order->getMeta('dispatch_outcome') === self::NO_DRIVER_FOUND_OUTCOME) {
            return self::NO_DRIVER_FOUND_OUTCOME;
        }

        if ($order->status !== 'canceled') {
            return null;
        }

        $statuses = OrderOffer::where('order_uuid', $order->uuid)->pluck('status')->unique()->values()->all();

        return $statuses !== [] && array_diff($statuses, [OrderOffer::DECLINED, OrderOffer::EXPIRED]) === [] ? self::NO_DRIVER_FOUND_OUTCOME : null;
    }
}
