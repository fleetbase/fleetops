<?php

namespace Fleetbase\FleetOps\Support\Rides;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Fleetbase\FleetOps\Models\ServiceRate;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Prices a finished ride from what actually happened: the distance the car's positions cover
 * from the start of the trip, and the time since it started, put through the same service rate
 * the rider booked. The result is stored on the order (`meta.fare`) for the receipt, and bought
 * as the order's price, which gives Ledger a new invoice for the final amount.
 */
class FinalFare
{
    /**
     * Price the ride once it is completed. Returns the fare, or null if it isn't a ride that can be priced.
     */
    public static function settle(Order $order): ?array
    {
        if (!RideUpdates::isRide($order) || $order->status !== 'completed' || data_get($order->meta, 'fare.final')) {
            return null;
        }

        $rate = data_get($order, 'purchaseRate.serviceQuote.serviceRate');
        if (!$rate instanceof ServiceRate) {
            return null;
        }

        $startedAt              = static::tripStartedAt($order);
        [$distance, $source]    = static::distance($order, $startedAt);
        $duration               = max(0, (int) $startedAt->diffInSeconds(Carbon::now(), true));
        $stops                  = collect([$order->payload?->pickup, $order->payload?->dropoff])->filter()->values();
        [$subTotal, $lines]     = $rate->quoteFromPreliminaryData([], $stops, $distance, $duration, false, 2);
        $estimate               = data_get($order, 'purchaseRate.serviceQuote.amount');

        $fare = [
            'final'           => true,
            'total'           => (int) $subTotal,
            'currency'        => $rate->currency,
            'estimate'        => is_numeric($estimate) ? (int) $estimate : null,
            'distance_m'      => $distance,
            'duration_s'      => $duration,
            'distance_source' => $source,
            'lines'           => collect($lines)->values()->map(fn ($line, $index) => [
                'label'  => static::label($rate, (string) data_get($line, 'details'), $index),
                'amount' => (int) data_get($line, 'amount', 0),
                'code'   => data_get($line, 'code'),
            ])->all(),
        ];

        // The fare goes on the order first, so Ledger sees a final fare when the new price arrives.
        $order->updateMeta('fare', $fare);
        $order->purchaseServiceQuote(static::quote($order, $rate, $fare, $lines));

        return $fare;
    }

    /**
     * When the rider got in: the trip's `in_progress` step, else when the driver started.
     */
    public static function tripStartedAt(Order $order): Carbon
    {
        $boarded = $order->tracking_number_uuid
            ? TrackingStatus::where('tracking_number_uuid', $order->tracking_number_uuid)->where('code', 'IN_PROGRESS')->oldest()->value('created_at')
            : null;

        return Carbon::parse($boarded ?? $order->started_at ?? $order->created_at ?? Carbon::now());
    }

    /**
     * Metres the car's recorded positions cover since the trip started, and where the figure
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
            return 'Base fare';
        }

        return match ($rate->rate_calculation_method) {
            'per_meter'            => 'Distance',
            'algo', 'algorithm'    => 'Distance and time',
            default                => $details !== '' ? $details : 'Fare',
        };
    }

    protected static function quote(Order $order, ServiceRate $rate, array $fare, iterable $lines): ServiceQuote
    {
        $quote = ServiceQuote::create([
            'request_id'        => 'final_' . Str::lower(Str::random(12)),
            'company_uuid'      => $order->company_uuid,
            'service_rate_uuid' => $rate->uuid,
            'amount'            => $fare['total'],
            'currency'          => $fare['currency'],
            'meta'              => ['final' => true, 'order' => $order->public_id, 'distance_m' => $fare['distance_m'], 'duration_s' => $fare['duration_s']],
        ]);

        foreach ($fare['lines'] as $line) {
            ServiceQuoteItem::create([
                'service_quote_uuid' => $quote->uuid,
                'amount'             => $line['amount'],
                'currency'           => $fare['currency'],
                'details'            => $line['label'],
                'code'               => $line['code'],
            ]);
        }

        return $quote;
    }
}
