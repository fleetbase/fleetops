<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\Models\Transaction;

class EarningsMetric extends MoneyMetric
{
    public static function slug(): string
    {
        return 'earnings';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = Transaction::where('company_uuid', $this->company->uuid)
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
}
