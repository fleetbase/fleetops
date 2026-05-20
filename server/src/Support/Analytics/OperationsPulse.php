<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Metrics\OrdersInProgressMetric;
use Illuminate\Support\Carbon;

/**
 * Real-time operational snapshot. Returns a small map of tile data + deltas
 * vs the same window 24 hours ago for the rate-of-change indicators.
 */
class OperationsPulse extends AbstractAnalytics
{
    public function get(): array
    {
        $companyUuid = $this->company->uuid;

        $activeOrders        = Order::where('company_uuid', $companyUuid)
            ->whereIn('status', OrdersInProgressMetric::IN_PROGRESS_STATUSES)
            ->count();

        $driversOnline       = Driver::where('company_uuid', $companyUuid)
            ->where('online', true)
            ->whereNotNull('current_job_uuid')
            ->count();

        $totalDrivers        = Driver::where('company_uuid', $companyUuid)->count();

        $vehiclesDeployed    = Vehicle::where('company_uuid', $companyUuid)
            ->whereHas('driver', fn ($q) => $q->whereNotNull('current_job_uuid'))
            ->count();

        $totalVehicles       = Vehicle::where('company_uuid', $companyUuid)->count();

        $issuesOpen          = Issue::where('company_uuid', $companyUuid)
            ->where('status', 'pending')
            ->count();

        $todayStart          = Carbon::today();
        $yesterdayStart      = Carbon::yesterday();
        $todaySoFar          = Carbon::now();
        $yesterdaySamePoint  = $yesterdayStart->copy()->setTime($todaySoFar->hour, $todaySoFar->minute);

        $completedToday      = Order::where('company_uuid', $companyUuid)
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$todayStart, $todaySoFar])
            ->count();

        $completedYesterdayToTime = Order::where('company_uuid', $companyUuid)
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$yesterdayStart, $yesterdaySamePoint])
            ->count();

        return [
            'active_orders'     => ['value' => $activeOrders, 'delta_pct' => null],
            'completed_today'   => [
                'value'     => $completedToday,
                'delta_pct' => $this->deltaPct($completedToday, $completedYesterdayToTime),
            ],
            'drivers_online'    => [
                'value'      => $driversOnline,
                'of'         => $totalDrivers,
                'pct_of_max' => $totalDrivers > 0 ? round(($driversOnline / $totalDrivers) * 100, 1) : 0.0,
            ],
            'vehicles_deployed' => [
                'value'      => $vehiclesDeployed,
                'of'         => $totalVehicles,
                'pct_of_max' => $totalVehicles > 0 ? round(($vehiclesDeployed / $totalVehicles) * 100, 1) : 0.0,
            ],
            'issues_open'       => ['value' => $issuesOpen, 'delta_pct' => null],
        ];
    }

    private function deltaPct(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
