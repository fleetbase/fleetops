<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Time-series of revenue (sum of transactions) per bucket, filtered to the
 * company's primary currency. Returns Chart.js-shaped { labels, datasets, summary }.
 */
class RevenueTrend extends AbstractAnalytics
{
    protected string $groupBy = 'day';

    public function groupBy(string $unit): self
    {
        $this->groupBy = in_array($unit, ['day', 'week', 'month'], true) ? $unit : 'day';

        return $this;
    }

    public function get(): array
    {
        $currency  = $this->companyCurrency();
        $start     = $this->start ?? Carbon::now()->subDays(30)->toDateTime();
        $end       = $this->end ?? Carbon::now()->toDateTime();

        $format    = match ($this->groupBy) {
            'week'  => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $rows = Transaction::where('company_uuid', $this->company->uuid)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket, SUM(amount) as total', [$format])
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $labels = $rows->pluck('bucket')->all();
        $data   = $rows->pluck('total')->map(fn ($v) => (float) $v)->all();
        $total  = array_sum($data);

        $duration       = $end->getTimestamp() - $start->getTimestamp();
        $compareStart   = (clone $start);
        $compareStart   = (new \DateTime())->setTimestamp($start->getTimestamp() - $duration);
        $previousTotal  = (float) Transaction::where('company_uuid', $this->company->uuid)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$compareStart, $start])
            ->sum('amount');

        $deltaPct = $previousTotal > 0
            ? round((($total - $previousTotal) / $previousTotal) * 100, 1)
            : ($total > 0 ? 100.0 : 0.0);

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => 'Revenue',
                    'data'            => $data,
                    'borderColor'     => '#3485e2',
                    'backgroundColor' => 'rgba(52, 133, 226, 0.1)',
                    'fill'            => true,
                    'tension'         => 0.3,
                ],
            ],
            'summary' => [
                'total'     => round($total, 2),
                'currency'  => $currency,
                'delta_pct' => $deltaPct,
            ],
        ];
    }
}
