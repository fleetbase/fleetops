<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

class OrdersScheduledMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'orders_scheduled';
    }

    public function format(): string
    {
        return 'count';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->where('status', 'created')
            ->whereDate('scheduled_at', '>', Carbon::now());

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
