<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;

class TotalDistanceTraveledMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'total_distance_traveled';
    }

    public function format(): string
    {
        return 'meters';
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
        return (float) $query->sum('distance');
    }
}
