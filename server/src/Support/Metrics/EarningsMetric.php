<?php

namespace Fleetbase\FleetOps\Support\Metrics;

class EarningsMetric extends MoneyMetric
{
    public static function slug(): string
    {
        return 'earnings';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        return ActiveRevenueQuery::forCompany($this->company, $this->currency(), $start, $end);
    }

    protected function aggregate($query): float
    {
        return (float) $query->sum('amount');
    }
}
