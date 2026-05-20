<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Driver;

class TotalDriversMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'total_drivers';
    }

    public function format(): string
    {
        return 'count';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        return Driver::where('company_uuid', $this->company->uuid);
    }

    protected function aggregate($query): int
    {
        return (int) $query->count();
    }
}
