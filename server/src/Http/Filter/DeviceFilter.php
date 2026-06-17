<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;

class DeviceFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function status(string|array $status)
    {
        $status = Utils::arrayFrom($status);

        if ($status) {
            $this->builder->whereIn('status', $status);
        }
    }

    public function deviceId(?string $deviceId)
    {
        if ($deviceId) {
            $this->builder->where('device_id', 'like', '%' . $deviceId . '%');
        }
    }

    public function telematic(?string $telematic)
    {
        $this->builder->where('telematic_uuid', $telematic);
    }

    public function telematicUuid(?string $telematic)
    {
        $this->telematic($telematic);
    }

    public function provider(?string $provider)
    {
        $this->builder->where('provider', $provider);
    }

    public function warrantyUuid(?string $warranty)
    {
        $this->builder->where('warranty_uuid', $warranty);
    }

    public function attachableType(?string $attachableType)
    {
        $this->builder->where('attachable_type', $attachableType);
    }

    public function attachableUuid(?string $attachable)
    {
        $this->builder->where('attachable_uuid', $attachable);
    }

    public function vehicle(?string $vehicle)
    {
        if ($vehicle) {
            $this->builder->where('attachable_uuid', $vehicle);
        }
    }

    public function connectionStatus(string|array $connectionStatus)
    {
        $statuses = Utils::arrayFrom($connectionStatus);

        if (!$statuses) {
            return;
        }

        $this->builder->where(function ($query) use ($statuses) {
            foreach ($statuses as $status) {
                match ($status) {
                    'online'           => $query->orWhere('last_online_at', '>=', now()->subMinutes(10)),
                    'recently_offline' => $query->orWhereBetween('last_online_at', [now()->subMinutes(60), now()->subMinutes(10)]),
                    'offline'          => $query->orWhereBetween('last_online_at', [now()->subDay(), now()->subMinutes(60)]),
                    'long_offline'     => $query->orWhere('last_online_at', '<', now()->subDay()),
                    'never_connected'  => $query->orWhereNull('last_online_at'),
                    default            => null,
                };
            }
        });
    }

    public function attachmentState(?string $attachmentState)
    {
        if ($attachmentState === 'attached') {
            $this->builder->whereNotNull('attachable_uuid');
        }

        if ($attachmentState === 'unattached') {
            $this->builder->whereNull('attachable_uuid');
        }
    }

    public function lastOnlineAt(string|array $lastOnlineAt)
    {
        $this->filterDate('last_online_at', $lastOnlineAt);
    }

    public function updatedAt(string|array $updatedAt)
    {
        $this->filterDate('updated_at', $updatedAt);
    }

    protected function filterDate(string $column, string|array $value): void
    {
        $dates = Utils::dateRange($value);

        if (is_array($dates)) {
            $this->builder->whereBetween($column, $dates);
        } else {
            $this->builder->whereDate($column, $dates);
        }
    }
}
