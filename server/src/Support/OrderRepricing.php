<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Prices a completed order again from what actually happened, when its config's `pricing`
 * policy asks for it: the distance the driver's positions cover from the metering activity (or
 * from when the order started), and the time since then, put through the service rate the order
 * was quoted on. The result is bought as the order's new purchase rate, which voids the old
 * transaction and, with Ledger, gives a new invoice for the final amount. The purchase rate's
 * `meta.repriced` says it was priced this way, with the distance and time it used.
 */
class OrderRepricing
{
    /**
     * Price the order again once it is completed. Returns the new quote, or null when the
     * policy is off, the order isn't completed or was already priced again, or it has no rate.
     */
    public static function settle(Order $order): ?ServiceQuote
    {
        $config = $order->currentConfig();
        if (!$config || !$config->policy('pricing.reprice_on_completion') || $order->status !== $config->getCompletedActivity()?->code) {
            return null;
        }

        if (data_get($order, 'purchaseRate.meta.repriced')) {
            return null;
        }

        $rate = data_get($order, 'purchaseRate.serviceQuote.serviceRate');
        if (!$rate instanceof ServiceRate) {
            return null;
        }

        $meteredFrom         = static::meteringStartedAt($order, $config->policy('pricing.metering_starts_at'));
        [$distance, $source] = static::distance($order, $meteredFrom);
        $duration            = max(0, (int) $meteredFrom->diffInSeconds(Carbon::now(), true));
        $stops               = collect([$order->payload?->pickup, $order->payload?->dropoff])->filter()->values();
        [$subTotal, $lines]  = $rate->quoteFromPreliminaryData([], $stops, $distance, $duration, false, 2);

        $quote = static::quote($order, $rate, (int) $subTotal, $distance, $duration, $lines);
        $order->purchaseServiceQuote($quote, [
            'repriced' => [
                'distance'        => $distance,
                'time'            => $duration,
                'distance_source' => $source,
                'metered_from'    => $meteredFrom->toISOString(),
                'previous_amount' => data_get($order, 'purchaseRate.serviceQuote.amount'),
            ],
        ]);

        return $quote;
    }

    /**
     * When metering began: the first time the order reached the activity, else when it started.
     */
    public static function meteringStartedAt(Order $order, ?string $activityCode = null): Carbon
    {
        $reached = $activityCode && $order->tracking_number_uuid
            ? TrackingStatus::where('tracking_number_uuid', $order->tracking_number_uuid)->where('code', TrackingStatus::prepareCode($activityCode))->oldest()->value('created_at')
            : null;

        return Carbon::parse($reached ?? $order->started_at ?? $order->created_at ?? Carbon::now());
    }

    /**
     * Metres the driver's recorded positions cover since metering began, and where the figure
     * came from. With too few positions it falls back to the straight line between the stops.
     */
    public static function distance(Order $order, Carbon $since): array
    {
        $points = Position::where('order_uuid', $order->uuid)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Position $position) => $position->coordinates)
            ->filter(fn ($point) => Utils::isPoint($point))
            ->values();

        $metres = 0.0;
        for ($i = 1; $i < $points->count(); $i++) {
            $metres += Utils::vincentyGreatCircleDistance($points[$i - 1], $points[$i]);
        }
        if ($metres > 0) {
            return [(int) round($metres), 'positions'];
        }

        $pickup  = $order->payload?->pickup?->location;
        $dropoff = $order->payload?->dropoff?->location;

        return Utils::isPoint($pickup) && Utils::isPoint($dropoff) ? [(int) round(Utils::vincentyGreatCircleDistance($pickup, $dropoff)), 'straight_line'] : [0, 'none'];
    }

    protected static function label(ServiceRate $rate, string $details, int $index): string
    {
        if ($index === 0) {
            return 'Base';
        }

        return match ($rate->rate_calculation_method) {
            'per_meter'         => 'Distance',
            'algo', 'algorithm' => 'Distance and time',
            default             => $details !== '' ? $details : 'Charge',
        };
    }

    protected static function quote(Order $order, ServiceRate $rate, int $amount, int $distance, int $duration, iterable $lines): ServiceQuote
    {
        $quote = ServiceQuote::create([
            'request_id'        => 'reprice_' . Str::lower(Str::random(12)),
            'company_uuid'      => $order->company_uuid,
            'service_rate_uuid' => $rate->uuid,
            'payload_uuid'      => $order->payload_uuid,
            'amount'            => $amount,
            'currency'          => $rate->currency,
            'distance'          => $distance,
            'time'              => $duration,
        ]);

        foreach (collect($lines)->values() as $index => $line) {
            ServiceQuoteItem::create([
                'service_quote_uuid' => $quote->uuid,
                'amount'             => (int) data_get($line, 'amount', 0),
                'currency'           => $rate->currency,
                'details'            => static::label($rate, (string) data_get($line, 'details'), $index),
                'code'               => data_get($line, 'code'),
            ]);
        }

        return $quote;
    }
}
