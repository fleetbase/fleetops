<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;

/**
 * Average revenue per completed order in the period. Computed as
 * sum(transactions.amount, primary_currency) / count(completed orders).
 *
 * Uses $this->currentStart/$currentEnd (set by the base class) so the metric
 * cooperates with the sparkline machinery — each bucket recomputes both
 * numerator and denominator over its own sub-range.
 */
class AvgOrderValueMetric extends MoneyMetric
{
    public static function slug(): string
    {
        return 'avg_order_value';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->where('status', 'completed');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query;
    }

    protected function aggregate($query): float
    {
        $orderCount = (int) $query->count();
        if ($orderCount === 0) {
            return 0.0;
        }

        $revenueQuery = ActiveRevenueQuery::forCompany($this->company, $this->currency(), $this->currentStart, $this->currentEnd);

        $totalRevenue = (float) $revenueQuery->sum('amount');

        return round($totalRevenue / $orderCount, 2);
    }
}
