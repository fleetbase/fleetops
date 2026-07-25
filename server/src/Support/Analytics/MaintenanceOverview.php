<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Maintenance;
use Illuminate\Support\Carbon;

/**
 * Maintenance summary: overdue count, next-7-day schedule, in-progress count,
 * month-to-date and year-to-date spend, plus a short list of upcoming items.
 */
class MaintenanceOverview extends AbstractAnalytics
{
    public function get(): array
    {
        $companyUuid = $this->company->uuid;
        $currency    = $this->companyCurrency();
        $now         = Carbon::now();

        $overdue = $this->overdueCount($companyUuid, $now);

        $next7d = $this->nextSevenDaysCount($companyUuid, $now);

        $inProgress = $this->inProgressCount($companyUuid);

        $costThisMonth = (float) $this->costThisMonth($companyUuid, $currency, $now);

        $costYtd = (float) $this->costYtd($companyUuid, $currency, $now);

        $upcoming = $this->upcomingMaintenance($companyUuid, $now);

        return [
            'overdue'           => $overdue,
            'scheduled_next_7d' => $next7d,
            'in_progress'       => $inProgress,
            'cost_this_month'   => round($costThisMonth, 2),
            'cost_ytd'          => round($costYtd, 2),
            'currency'          => $currency,
            'upcoming'          => $upcoming->map(fn ($m) => [
                'uuid'         => $m->uuid,
                'type'         => $m->type,
                'priority'     => $m->priority,
                'scheduled_at' => $m->scheduled_at,
            ])->all(),
        ];
    }

    protected function overdueCount(string $companyUuid, Carbon $now): int
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->where('scheduled_at', '<', $now)
            ->where('status', '!=', 'completed')
            ->where('status', '!=', 'canceled')
            ->count();
    }

    protected function nextSevenDaysCount(string $companyUuid, Carbon $now): int
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->whereBetween('scheduled_at', [$now, $now->copy()->addDays(7)])
            ->whereIn('status', ['scheduled', 'pending'])
            ->count();
    }

    protected function inProgressCount(string $companyUuid): int
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->where('status', 'in_progress')
            ->count();
    }

    protected function costThisMonth(string $companyUuid, string $currency, Carbon $now): int|float
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->where('currency', $currency)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$now->copy()->startOfMonth(), $now])
            ->sum('total_cost');
    }

    protected function costYtd(string $companyUuid, string $currency, Carbon $now): int|float
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->where('currency', $currency)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$now->copy()->startOfYear(), $now])
            ->sum('total_cost');
    }

    protected function upcomingMaintenance(string $companyUuid, Carbon $now)
    {
        return Maintenance::where('company_uuid', $companyUuid)
            ->whereIn('status', ['scheduled', 'pending'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', $now)
            ->orderBy('scheduled_at', 'asc')
            ->limit(8)
            ->get(['uuid', 'maintainable_uuid', 'maintainable_type', 'type', 'priority', 'scheduled_at']);
    }
}
