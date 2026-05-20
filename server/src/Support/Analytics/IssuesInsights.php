<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * Issues breakdown: category pie, priority histogram, open vs resolved counts,
 * and average resolution time (hours).
 */
class IssuesInsights extends AbstractAnalytics
{
    public function get(): array
    {
        $companyUuid = $this->company->uuid;
        $start       = $this->start ?? Carbon::now()->subDays(30)->toDateTime();
        $end         = $this->end ?? Carbon::now()->toDateTime();

        $byCategory = Issue::where('company_uuid', $companyUuid)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->orderByRaw('total DESC')
            ->get();

        $byPriority = Issue::where('company_uuid', $companyUuid)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $open = Issue::where('company_uuid', $companyUuid)
            ->where('status', 'pending')
            ->count();

        $resolvedInWindow = Issue::where('company_uuid', $companyUuid)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$start, $end])
            ->get(['created_at', 'resolved_at']);

        $totalHours = 0.0;
        foreach ($resolvedInWindow as $issue) {
            $totalHours += (Carbon::parse($issue->resolved_at)->getTimestamp()
                          - Carbon::parse($issue->created_at)->getTimestamp()) / 3600;
        }

        $avgResolutionHours = $resolvedInWindow->count() > 0
            ? round($totalHours / $resolvedInWindow->count(), 1)
            : null;

        return [
            'by_category' => [
                'labels' => $byCategory->pluck('category')->map(fn ($c) => $c ?: 'Uncategorized')->all(),
                'data'   => $byCategory->pluck('total')->map(fn ($v) => (int) $v)->all(),
            ],
            'by_priority' => [
                'high'   => (int) ($byPriority['high'] ?? 0),
                'medium' => (int) ($byPriority['medium'] ?? 0),
                'low'    => (int) ($byPriority['low'] ?? 0),
            ],
            'open'                   => $open,
            'resolved_this_period'   => $resolvedInWindow->count(),
            'avg_resolution_hours'   => $avgResolutionHours,
        ];
    }
}
