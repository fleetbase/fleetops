<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Support\Str;

class IssueFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function publicId(?string $publicId)
    {
        $this->builder->searchWhere('public_id', $publicId);
    }

    public function reporter(?string $reporter)
    {
        $this->builder->whereHas('reportedBy', function ($q) use ($reporter) {
            if (Str::isUuid($reporter)) {
                $q->where('uuid', $reporter);
            } else {
                $q->search($reporter);
            }
        });
    }

    public function assignee(?string $assignee)
    {
        $this->builder->whereHas('assignedBy', function ($q) use ($assignee) {
            if (Str::isUuid($assignee)) {
                $q->where('uuid', $assignee);
            } else {
                $q->search($assignee);
            }
        });
    }

    public function createdAt($createdAt)
    {
        $createdAt = Utils::dateRange($createdAt);

        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }

    public function updatedAt($updatedAt)
    {
        $updatedAt = Utils::dateRange($updatedAt);

        if (is_array($updatedAt)) {
            $this->builder->whereBetween('updated_at', $updatedAt);
        } else {
            $this->builder->whereDate('updated_at', $updatedAt);
        }
    }
}
