<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Manifest;
use Fleetbase\FleetOps\Models\ManifestStop;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a driver did on one day, from stored records only.
 *
 * Every figure here is counted from a row that exists. Where the records cannot
 * support a figure it is null rather than estimated, and the key says what the
 * figure is: `planned_distance_m` is the route's planned length, not what was
 * driven; `cash_on_delivery_due` is what the completed orders asked for in cash,
 * because nothing records that the cash changed hands.
 */
class DriverDaySummary
{
    /**
     * Past this many recorded positions in one day the driven distance is left
     * out: a driver's app reports a few thousand a day, so more than this is a
     * simulator or a fault, and not worth reading on a request a phone waits on.
     */
    public const MAX_POSITIONS = 20000;

    /**
     * Positions closer together than this are treated as standing still, the
     * same threshold the driver's own position history uses. Without it GPS
     * drift at a loading bay adds up to distance nobody drove.
     */
    public const MIN_MOVE_M = 50;

    /**
     * @param string|null $date     a day as YYYY-MM-DD; today when null
     * @param string|null $timezone where that day is cut. A driver's phone knows
     *                              where it is better than a profile field that
     *                              may never have been set, so the app says; when
     *                              it does not, the driver's own is used
     */
    public static function for(Driver $driver, ?string $date = null, ?string $timezone = null): array
    {
        $timezone = static::isTimezone($timezone) ? $timezone : static::timezoneOf($driver);
        $day      = $date ? Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay() : Carbon::now($timezone)->startOfDay();
        $from     = $day->copy()->utc();
        $to       = $day->copy()->addDay()->utc();

        // The day's routes. `scheduled_date` is a calendar date, not a moment,
        // so it is matched as written rather than shifted through a timezone.
        $manifests = Manifest::where('company_uuid', $driver->company_uuid)
            ->where('driver_uuid', $driver->uuid)
            ->whereDate('scheduled_date', $day->toDateString())
            ->where('status', '!=', 'cancelled')
            ->get()
            ->each->setAppends([]);

        $stops = $manifests->isEmpty()
            ? collect()
            : ManifestStop::whereIn('manifest_uuid', $manifests->pluck('uuid'))->get(['uuid', 'manifest_uuid', 'order_uuid', 'status', 'actual_arrival'])->each->setAppends([]);

        $completedOrders = static::ordersCompletedBetween($driver, $from, $to);
        $onRoute         = $stops->pluck('order_uuid')->filter()->unique()->flip();
        $loose           = $completedOrders->reject(fn (array $order) => $onRoute->has($order['uuid']));
        $looseWaypoints  = static::waypointCountOf($loose);

        $completedStops = $stops->where('status', 'completed')->count();
        $skippedStops   = $stops->where('status', 'skipped')->count();

        $duty      = DriverDuty::within($driver, $from, $to);
        $positions = static::positionsBetween($driver, $from, $to);

        $moments = collect([
            $duty['first_at'],
            $duty['last_at'],
            $positions['first_at'],
            $positions['last_at'],
        ])
            ->merge($stops->pluck('actual_arrival'))
            ->merge($completedOrders->pluck('completed_at'))
            ->merge($manifests->pluck('started_at'))
            ->merge($manifests->pluck('completed_at'))
            ->filter(fn ($moment) => $moment instanceof \DateTimeInterface && $moment >= $from && $moment < $to)
            ->sort()
            ->values();

        $plannedDistance = $manifests->pluck('total_distance_m')->filter(fn ($metres) => $metres !== null);

        return [
            'driver'                => $driver->public_id,
            'date'                  => $day->toDateString(),
            'timezone'              => $timezone,
            'from'                  => $from,
            'to'                    => $to,
            'online'                => (bool) $driver->online,
            'online_since'          => $driver->online_since,
            'seconds_on_duty'       => $duty['seconds'],
            'waypoints'             => [
                'completed' => $completedStops + $looseWaypoints,
                'skipped'   => $skippedStops,
                'total'     => $stops->count() + $looseWaypoints,
            ],
            'orders_completed'      => $completedOrders->count(),
            'planned_distance_m'    => $plannedDistance->isEmpty() ? null : (int) $plannedDistance->sum(),
            'distance_driven_m'     => $positions['metres'],
            'cash_on_delivery_due'  => static::cashDueOn($completedOrders),
            'first_activity_at'     => $moments->first(),
            'last_activity_at'      => $moments->last(),
        ];
    }

    /**
     * The driver's own timezone, else their organization's, else the server's.
     * The same fallback the order endpoints use for an organization's dates.
     */
    protected static function timezoneOf(Driver $driver): string
    {
        $driver->loadMissing('user');

        foreach ([data_get($driver, 'user.timezone'), data_get($driver->company()->first(['uuid', 'timezone']), 'timezone'), config('app.timezone')] as $timezone) {
            if (static::isTimezone($timezone)) {
                return $timezone;
            }
        }

        return 'UTC';
    }

    public static function isTimezone($timezone): bool
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }

    /**
     * Orders assigned to the driver whose completing activity was recorded in
     * the window.
     *
     * An order has no `completed_at`. What it has is the tracking status its
     * completing activity wrote, so that row's time is when it was completed.
     * `updated_at` narrows the orders looked at: completing an order writes its
     * status, so one completed in the window cannot have been last touched
     * before the window opened.
     *
     * @return Collection<int, array{uuid: string, payload_uuid: string|null, completed_at: Carbon}>
     */
    protected static function ordersCompletedBetween(Driver $driver, Carbon $from, Carbon $to): Collection
    {
        $orders = Order::query()
            ->setEagerLoads([])
            ->where('company_uuid', $driver->company_uuid)
            ->where('driver_assigned_uuid', $driver->uuid)
            ->where('status', 'completed')
            ->where('updated_at', '>=', $from)
            ->whereNotNull('tracking_number_uuid')
            ->toBase()
            ->get(['uuid', 'payload_uuid', 'tracking_number_uuid']);

        if ($orders->isEmpty()) {
            return collect();
        }

        $completedAt = TrackingStatus::query()
            ->setEagerLoads([])
            ->whereIn('tracking_number_uuid', $orders->pluck('tracking_number_uuid'))
            ->where(fn ($query) => $query->where('complete', 1)->orWhere('code', 'COMPLETED'))
            ->toBase()
            ->selectRaw('tracking_number_uuid, MAX(created_at) as completed_at')
            ->groupBy('tracking_number_uuid')
            ->pluck('completed_at', 'tracking_number_uuid');

        return $orders
            ->map(function ($order) use ($completedAt) {
                $at = $completedAt->get($order->tracking_number_uuid);

                return $at ? ['uuid' => $order->uuid, 'payload_uuid' => $order->payload_uuid, 'completed_at' => Carbon::parse($at, 'UTC')] : null;
            })
            ->filter(fn ($order) => $order && $order['completed_at'] >= $from && $order['completed_at'] < $to)
            ->values();
    }

    /**
     * How many places these orders visit: each order's pickup and drop-off,
     * where it has them, and every waypoint between.
     */
    protected static function waypointCountOf(Collection $orders): int
    {
        $payloadUuids = $orders->pluck('payload_uuid')->filter()->unique()->values();
        if ($payloadUuids->isEmpty()) {
            return 0;
        }

        $ends = Payload::query()->setEagerLoads([])->whereIn('uuid', $payloadUuids)->toBase()->get(['pickup_uuid', 'dropoff_uuid'])
            ->sum(fn ($payload) => ($payload->pickup_uuid ? 1 : 0) + ($payload->dropoff_uuid ? 1 : 0));

        return $ends + Waypoint::query()->setEagerLoads([])->whereIn('payload_uuid', $payloadUuids)->count();
    }

    /**
     * Cash the completed orders asked the driver to collect, per currency.
     *
     * "Due", not "collected": a payload says how much is owed and by what
     * method, and nothing anywhere records the money being handed over. Amounts
     * are in the currency's minor units, as payloads store them.
     *
     * @return array<int, array{currency: string|null, amount: int, orders: int}>
     */
    protected static function cashDueOn(Collection $orders): array
    {
        $payloadUuids = $orders->pluck('payload_uuid')->filter()->unique()->values();
        if ($payloadUuids->isEmpty()) {
            return [];
        }

        return Payload::query()
            ->setEagerLoads([])
            ->whereIn('uuid', $payloadUuids)
            ->where('cod_payment_method', 'cash')
            ->toBase()
            ->get(['cod_amount', 'cod_currency'])
            ->filter(fn ($payload) => (int) $payload->cod_amount > 0)
            ->groupBy(fn ($payload) => (string) $payload->cod_currency)
            ->map(fn (Collection $group, string $currency) => [
                'currency' => $currency !== '' ? $currency : null,
                'amount'   => (int) $group->sum(fn ($payload) => (int) $payload->cod_amount),
                'orders'   => $group->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Metres between the driver's recorded positions in the window, in the
     * order they were recorded.
     *
     * Straight lines between fixes, so a little under the road distance, and
     * only as complete as the app's reporting was: a phone that stopped
     * reporting leaves a gap that is joined by one straight line. Null when
     * there are too few fixes to measure, or too many to read on a request.
     *
     * @return array{metres: int|null, first_at: Carbon|null, last_at: Carbon|null}
     */
    protected static function positionsBetween(Driver $driver, Carbon $from, Carbon $to): array
    {
        $query = Position::query()
            ->where('subject_uuid', $driver->uuid)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $count = (clone $query)->count();
        if ($count < 2 || $count > static::MAX_POSITIONS) {
            return ['metres' => null, 'first_at' => null, 'last_at' => null];
        }

        $metres = 0.0;
        $anchor = null;
        $first  = null;
        $last   = null;

        foreach ($query->orderBy('created_at')->orderBy('id')->cursor() as $position) {
            $point = $position->coordinates;
            // A driver with no fix yet is stored at 0,0; that is not a place they were.
            if (!Utils::isPoint($point) || ((float) $point->getLat() === 0.0 && (float) $point->getLng() === 0.0)) {
                continue;
            }

            $first = $first ?? $position->created_at;
            $last  = $position->created_at;

            if ($anchor === null) {
                $anchor = $point;
                continue;
            }

            $step = Utils::vincentyGreatCircleDistance($anchor, $point);
            if ($step >= static::MIN_MOVE_M) {
                $metres += $step;
                $anchor = $point;
            }
        }

        return ['metres' => $first && $last && $first != $last ? (int) round($metres) : null, 'first_at' => $first, 'last_at' => $last];
    }
}
