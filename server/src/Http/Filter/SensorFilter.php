<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;

class SensorFilter extends Filter
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

    public function type(string|array $type)
    {
        $types = Utils::arrayFrom($type);

        if ($types) {
            $this->builder->whereIn('type', $types);
        }
    }

    public function sensorType(string|array $type)
    {
        $this->type($type);
    }

    public function status(string|array $status)
    {
        $statuses = Utils::arrayFrom($status);

        if ($statuses) {
            $this->builder->whereIn('status', $statuses);
        }
    }

    public function device(?string $device)
    {
        $this->wherePublicRelation('device_uuid', Device::class, $device);
    }

    public function deviceUuid(?string $device)
    {
        $this->device($device);
    }

    public function telematic(?string $telematic)
    {
        $this->wherePublicRelation('telematic_uuid', Telematic::class, $telematic);
    }

    public function telematicUuid(?string $telematic)
    {
        $this->telematic($telematic);
    }

    public function warrantyUuid(?string $warranty)
    {
        if ($warranty) {
            $this->builder->where('warranty_uuid', $warranty);
        }
    }

    public function sensorableType(?string $sensorableType)
    {
        if ($sensorableType) {
            $this->builder->where('sensorable_type', $sensorableType);
        }
    }

    public function serialNumber(?string $serialNumber)
    {
        if ($serialNumber) {
            $this->builder->where('serial_number', 'like', '%' . $serialNumber . '%');
        }
    }

    public function imei(?string $imei)
    {
        if ($imei) {
            $this->builder->where('imei', 'like', '%' . $imei . '%');
        }
    }

    public function lastReadingAt(string|array $lastReadingAt)
    {
        $this->filterDate('last_reading_at', $lastReadingAt);
    }

    public function createdAt(string|array $createdAt)
    {
        $this->filterDate('created_at', $createdAt);
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

    protected function wherePublicRelation(string $column, string $modelClass, ?string $identifier): void
    {
        if (!$identifier) {
            return;
        }

        $this->builder->whereIn($column, $this->resolvePublicRelationUuids($modelClass, $identifier, Http::isInternalRequest($this->request)));
    }

    protected function resolvePublicRelationUuids(string $modelClass, string $identifier, bool $allowUuid = false)
    {
        $instance = new $modelClass();

        return $modelClass::query()
            ->where('company_uuid', $this->session->get('company'))
            ->where(function ($query) use ($identifier, $instance, $allowUuid) {
                $query->where('public_id', $identifier);

                if (in_array('internal_id', $instance->getFillable())) {
                    $query->orWhere('internal_id', $identifier);
                }

                if ($allowUuid) {
                    $query->orWhere('uuid', $identifier);
                }
            })
            ->pluck('uuid');
    }
}
