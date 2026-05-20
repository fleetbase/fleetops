<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Percentage of completed orders that finished within the SLA window
 * (scheduled_at + sla_minutes). Orders without a scheduled_at are excluded
 * — you can't be on-time against an undefined target.
 */
class OnTimeDelivery extends AbstractAnalytics
{
    protected int $slaMinutes = 30;

    public function slaMinutes(int $minutes): self
    {
        $this->slaMinutes = max(0, $minutes);

        return $this;
    }

    public function get(): array
    {
        $start = $this->start ?? Carbon::now()->subDays(30)->toDateTime();
        $end   = $this->end ?? Carbon::now()->toDateTime();

        [$onTime, $late, $total] = $this->bucketCounts($start, $end);

        $duration            = $end->getTimestamp() - $start->getTimestamp();
        $compareEnd          = clone $start;
        $compareStart        = (new \DateTime())->setTimestamp($start->getTimestamp() - $duration);
        [, , $prevTotal]     = $this->bucketCounts($compareStart, $compareEnd);
        $prevOnTime          = $this->bucketCounts($compareStart, $compareEnd)[0];
        $prevPct             = $prevTotal > 0 ? ($prevOnTime / $prevTotal) * 100 : 0;
        $currentPct          = $total > 0 ? ($onTime / $total) * 100 : 0;
        $deltaPct            = round($currentPct - $prevPct, 1);

        return [
            'on_time_pct'   => round($currentPct, 1),
            'on_time'       => $onTime,
            'late'          => $late,
            'total'         => $total,
            'sla_minutes'   => $this->slaMinutes,
            'delta_pct'     => $deltaPct,
        ];
    }

    /** @return array{0:int,1:int,2:int} [onTime, late, total] */
    private function bucketCounts(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $slaSeconds = $this->slaMinutes * 60;

        $orders = Order::where('company_uuid', $this->company->uuid)
            ->where('status', 'completed')
            ->whereNotNull('scheduled_at')
            ->whereNotNull('updated_at')
            ->whereBetween('updated_at', [$start, $end])
            ->selectRaw('TIMESTAMPDIFF(SECOND, scheduled_at, updated_at) as drift_seconds')
            ->get();

        $onTime = 0;
        $late   = 0;
        foreach ($orders as $row) {
            if ((int) $row->drift_seconds <= $slaSeconds) {
                $onTime++;
            } else {
                $late++;
            }
        }

        return [$onTime, $late, $onTime + $late];
    }
}
