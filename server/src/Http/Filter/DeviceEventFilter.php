<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;

class DeviceEventFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where(
            function ($query) {
                $query->where('company_uuid', $this->session->get('company'));
            }
        );
    }

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function telematic(?string $telematic)
    {
        $this->builder->whereHas('device', function ($query) use ($telematic) {
            $query->whereHas('telematic', function ($query) use ($telematic) {
                $query->where('uuid', $telematic);
                $query->orWhere('public_id', $telematic);
            });
        });
    }

    public function device(?string $device)
    {
        $this->builder->whereHas('device', function ($query) use ($device) {
            $query->where('uuid', $device);
            $query->orWhere('public_id', $device);
        });
    }

    public function deviceUuid(?string $device)
    {
        $this->device($device);
    }

    public function severity(string|array $severity)
    {
        $severity = Utils::arrayFrom($severity);

        if ($severity) {
            $this->builder->whereIn('severity', $severity);
        }
    }

    public function processed(string|array $processed)
    {
        $states = Utils::arrayFrom($processed);

        if (!$states) {
            return;
        }

        $this->builder->where(function ($query) use ($states) {
            foreach ($states as $state) {
                match ($state) {
                    'processed'   => $query->orWhereNotNull('processed_at'),
                    'unprocessed' => $query->orWhereNull('processed_at'),
                    default       => null,
                };
            }
        });
    }

    public function occurredAt($occurredAt)
    {
        $occurredAt = Utils::dateRange($occurredAt);

        if (is_array($occurredAt)) {
            $this->builder->whereBetween('occurred_at', $occurredAt);
        } else {
            $this->builder->whereDate('occurred_at', $occurredAt);
        }
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
