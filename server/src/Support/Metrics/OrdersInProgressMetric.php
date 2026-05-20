<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;

/**
 * Counts orders currently in flight. Uses an explicit allowlist of "active" statuses
 * rather than the legacy exclusion list, which was brittle against new statuses.
 */
class OrdersInProgressMetric extends AbstractMetric
{
    public const IN_PROGRESS_STATUSES = [
        'dispatched',
        'assigned',
        'started',
        'enroute',
        'in_progress',
        'driver_enroute',
        'picking_up',
        'picked_up',
        'dropping_off',
    ];

    public static function slug(): string
    {
        return 'orders_in_progress';
    }

    public function format(): string
    {
        return 'count';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = Order::where('company_uuid', $this->company->uuid)
            ->whereIn('status', self::IN_PROGRESS_STATUSES);

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
