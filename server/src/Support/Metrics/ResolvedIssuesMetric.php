<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Issue;

class ResolvedIssuesMetric extends AbstractMetric
{
    public static function slug(): string
    {
        return 'resolved_issues';
    }

    public function format(): string
    {
        return 'count';
    }

    protected function query(?\DateTimeInterface $start, ?\DateTimeInterface $end)
    {
        $query = Issue::where('company_uuid', $this->company->uuid)
            ->whereNotNull('resolved_at');

        if ($start && $end) {
            $query->whereBetween('resolved_at', [$start, $end]);
        }

        return $query;
    }

    protected function aggregate($query): int
    {
        return (int) $query->count();
    }
}
