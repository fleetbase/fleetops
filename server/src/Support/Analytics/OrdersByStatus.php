<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Daily count of orders bucketed by status. One stacked bar per day, with
 * a fixed status palette so the colour coding stays consistent over time.
 */
class OrdersByStatus extends AbstractAnalytics
{
    /** Status → display label + colour for stacked bars. */
    private const STATUS_PALETTE = [
        'completed'   => ['label' => 'Completed',   'color' => '#22c55e'],
        'in_progress' => ['label' => 'In Progress', 'color' => '#3485e2'],
        'dispatched'  => ['label' => 'Dispatched',  'color' => '#8b5cf6'],
        'canceled'    => ['label' => 'Canceled',    'color' => '#ef4444'],
        'failed'      => ['label' => 'Failed',      'color' => '#f59e0b'],
    ];

    public function get(): array
    {
        $start = $this->start ?? Carbon::now()->subDays(14)->toDateTime();
        $end   = $this->end ?? Carbon::now()->toDateTime();

        $rows = Order::where('company_uuid', $this->company->uuid)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', array_keys(self::STATUS_PALETTE))
            ->selectRaw('DATE(created_at) as bucket, status, COUNT(*) as total')
            ->groupBy('bucket', 'status')
            ->orderBy('bucket')
            ->get();

        $cursor    = Carbon::instance($start)->startOfDay();
        $endDay    = Carbon::instance($end)->startOfDay();
        $labels    = [];
        $byStatus  = array_fill_keys(array_keys(self::STATUS_PALETTE), []);

        while ($cursor <= $endDay) {
            $labels[] = $cursor->format('M j');
            foreach (array_keys(self::STATUS_PALETTE) as $status) {
                $match               = $rows->first(fn ($r) => $r->bucket === $cursor->format('Y-m-d') && $r->status === $status);
                $byStatus[$status][] = $match ? (int) $match->total : 0;
            }
            $cursor->addDay();
        }

        $datasets = [];
        foreach (self::STATUS_PALETTE as $status => $cfg) {
            $datasets[] = [
                'label'           => $cfg['label'],
                'data'            => $byStatus[$status],
                'backgroundColor' => $cfg['color'],
            ];
        }

        return [
            'labels'   => $labels,
            'datasets' => $datasets,
        ];
    }
}
