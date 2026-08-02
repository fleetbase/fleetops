<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\FuelReport;

class FuelCostsMetric extends MoneyMetric
{
    public static function slug(): string
    {
        return 'fuel_costs';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = $this->fuelReportQuery($this->company->uuid)
            ->where('currency', $this->currency());

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        return $query;
    }

    protected function aggregate($query): float
    {
        return (float) $query->sum('amount');
    }

    protected function fuelReportQuery(string $companyUuid)
    {
        return FuelReport::where('company_uuid', $companyUuid);
    }
}
