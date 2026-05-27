<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Dual-axis fuel chart: weekly total cost (bars) + cost-per-km (line).
 * Cost-per-km is sum(FuelReport.amount in primary currency) divided by
 * sum(Order.distance, in km) for that bucket.
 */
class FuelEfficiency extends AbstractAnalytics
{
    public function get(): array
    {
        $currency = $this->companyCurrency();
        $start    = $this->start ?? Carbon::now()->subDays(90)->toDateTime();
        $end      = $this->end ?? Carbon::now()->toDateTime();

        $fuelByWeek = FuelReport::where('company_uuid', $this->company->uuid)
            ->where('currency', $currency)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('YEARWEEK(created_at, 1) as wk, MIN(DATE(created_at)) as wk_start, SUM(amount) as total_cost')
            ->groupBy('wk')
            ->orderBy('wk')
            ->get();

        $distanceByWeek = Order::where('company_uuid', $this->company->uuid)
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('YEARWEEK(updated_at, 1) as wk, SUM(distance) as total_distance')
            ->groupBy('wk')
            ->orderBy('wk')
            ->pluck('total_distance', 'wk');

        $labels    = [];
        $costData  = [];
        $cpkmData  = [];

        foreach ($fuelByWeek as $row) {
            $labels[]   = Carbon::parse($row->wk_start)->format('M j');
            $cost       = (float) $row->total_cost;
            $distanceM  = (float) ($distanceByWeek[$row->wk] ?? 0);
            $distanceKm = $distanceM / 1000.0;
            $costData[] = round($cost, 2);
            $cpkmData[] = $distanceKm > 0 ? round($cost / $distanceKm, 3) : null;
        }

        $totalCost      = array_sum($costData);
        $totalDistanceM = array_sum($distanceByWeek->all());
        $avgCostPerKm   = $totalDistanceM > 0
            ? round($totalCost / ($totalDistanceM / 1000.0), 3)
            : 0.0;

        return [
            'labels'   => $labels,
            'datasets' => [
                [
                    'type'            => 'bar',
                    'label'           => 'Fuel Cost',
                    'data'            => $costData,
                    'yAxisID'         => 'y1',
                    'backgroundColor' => '#3485e2',
                ],
                [
                    'type'        => 'line',
                    'label'       => 'Cost per km',
                    'data'        => $cpkmData,
                    'yAxisID'     => 'y2',
                    'borderColor' => '#f59e0b',
                    'tension'     => 0.3,
                ],
            ],
            'summary' => [
                'total_cost'       => round($totalCost, 2),
                'currency'         => $currency,
                'avg_cost_per_km'  => $avgCostPerKm,
            ],
        ];
    }
}
