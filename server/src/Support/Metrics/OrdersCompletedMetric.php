<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;

class OrdersCompletedMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'orders_completed';
    }

    public function format(): string
    {
        return 'count';
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

    protected function aggregate($query): int
    {
        return (int) $query->count();
    }
}
