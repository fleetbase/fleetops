<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Ranked driver leaderboard. Single GROUP BY join on orders → drivers so this
 * scales without N+1 even on large fleets.
 */
class TopDrivers extends AbstractAnalytics
{
    protected int $limit     = 10;
    protected string $sortBy = 'orders_completed';

    public function limit(int $limit): self
    {
        $this->limit = max(1, min(50, $limit));

        return $this;
    }

    public function sortBy(string $key): self
    {
        $this->sortBy = in_array($key, ['orders_completed', 'distance', 'on_time'], true)
            ? $key
            : 'orders_completed';

        return $this;
    }

    public function get(): array
    {
        $start = $this->start ?? Carbon::now()->subDays(30)->toDateTime();
        $end   = $this->end ?? Carbon::now()->toDateTime();

        $sortColumn = match ($this->sortBy) {
            'distance' => 'distance_m',
            'on_time'  => 'CASE
                WHEN SUM(CASE WHEN orders.scheduled_at IS NOT NULL THEN 1 ELSE 0 END) > 0
                THEN SUM(CASE
                    WHEN orders.scheduled_at IS NOT NULL
                     AND TIMESTAMPDIFF(SECOND, orders.scheduled_at, orders.updated_at) <= 1800
                    THEN 1 ELSE 0 END)
                    / SUM(CASE WHEN orders.scheduled_at IS NOT NULL THEN 1 ELSE 0 END)
                ELSE -1
            END',
            default    => 'orders_completed',
        };

        // `drivers.name` and `drivers.photo_uuid` aren't real columns — they're
        // virtual attributes that resolve via the related users row. Join users
        // explicitly so we can SELECT them at the SQL level.
        $rows = Order::where('orders.company_uuid', $this->company->uuid)
            ->where('orders.status', 'completed')
            ->whereNotNull('orders.driver_assigned_uuid')
            ->whereBetween('orders.updated_at', [$start, $end])
            ->join('drivers', 'drivers.uuid', '=', 'orders.driver_assigned_uuid')
            ->join('users', 'users.uuid', '=', 'drivers.user_uuid')
            ->groupBy('drivers.uuid', 'users.name', 'users.avatar_uuid')
            ->selectRaw('
                drivers.uuid as driver_uuid,
                users.name as name,
                users.avatar_uuid as avatar_uuid,
                COUNT(orders.uuid) as orders_completed,
                COALESCE(SUM(orders.distance), 0) as distance_m,
                SUM(CASE
                    WHEN orders.scheduled_at IS NOT NULL
                     AND TIMESTAMPDIFF(SECOND, orders.scheduled_at, orders.updated_at) <= 1800
                    THEN 1 ELSE 0 END) as on_time_count,
                SUM(CASE WHEN orders.scheduled_at IS NOT NULL THEN 1 ELSE 0 END) as scheduled_count
            ')
            ->orderByRaw("{$sortColumn} DESC")
            ->limit($this->limit)
            ->get();

        $result = $rows->map(function ($r) {
            $scheduled = (int) $r->scheduled_count;
            $onTimePct = $scheduled > 0
                ? round(((int) $r->on_time_count / $scheduled) * 100, 1)
                : null;

            return [
                'driver_uuid'      => $r->driver_uuid,
                'name'             => $r->name,
                'avatar_uuid'      => $r->avatar_uuid,
                'orders_completed' => (int) $r->orders_completed,
                'distance_m'       => (float) $r->distance_m,
                'on_time_pct'      => $onTimePct,
            ];
        })->all();

        return [
            'rows'    => $result,
            'sort_by' => $this->sortBy,
        ];
    }
}
