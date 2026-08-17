<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Support\Str;

class MaintenanceScheduleFilter extends Filter
{
    public function queryForInternal(): void
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic(): void
    {
        $this->queryForInternal();
    }

    public function subjectType(?string $subjectType): void
    {
        if (!$subjectType) {
            return;
        }

        if (!Str::contains($subjectType, ['\\', ':'])) {
            $subjectType = 'fleet-ops:' . $subjectType;
        }

        $this->builder->where('subject_type', Utils::getMutationType($subjectType));
    }

    public function subjectUuid(?string $subjectUuid): void
    {
        if ($subjectUuid) {
            $this->builder->where('subject_uuid', $subjectUuid);
        }
    }
}
