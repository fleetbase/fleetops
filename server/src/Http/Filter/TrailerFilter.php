<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;

class TrailerFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function query(?string $value)
    {
        $this->builder->search($value);
    }

    public function publicId(?string $value)
    {
        $this->builder->searchWhere('public_id', $value);
    }

    public function name(?string $value)
    {
        $this->builder->searchWhere('name', $value);
    }

    public function code(?string $value)
    {
        $this->builder->searchWhere('code', $value);
    }

    public function trailerType(?string $value)
    {
        $this->builder->where('type', $value);
    }

    public function status(?string $value)
    {
        $this->builder->where('status', $value);
    }

    public function trailerMake(?string $value)
    {
        $this->builder->searchWhere('make', $value);
    }

    public function trailerModel(?string $value)
    {
        $this->builder->searchWhere('model', $value);
    }

    public function trailerYear(?string $value)
    {
        $this->builder->where('year', $value);
    }

    public function plateNumber(?string $value)
    {
        $this->builder->searchWhere('plate_number', $value);
    }

    public function vin(?string $value)
    {
        $this->builder->searchWhere('vin', $value);
    }

    public function serialNumber(?string $value)
    {
        $this->builder->searchWhere('serial_number', $value);
    }

    public function length(?string $value)
    {
        $this->builder->where('length', $value);
    }

    public function axleCount(?string $value)
    {
        $this->builder->where('axle_count', $value);
    }

    public function gvwr(?string $value)
    {
        $this->builder->where('gvwr', $value);
    }

    public function payloadCapacity(?string $value)
    {
        $this->builder->where('payload_capacity', $value);
    }

    public function ownershipType(?string $value)
    {
        $this->builder->where('ownership_type', $value);
    }

    public function devicesCount(?string $value)
    {
        $this->builder->has('devices', '=', (int) $value);
    }

    public function equipmentCount(?string $value)
    {
        $this->builder->has('equipments', '=', (int) $value);
    }

    public function connectivityStatus(?string $value)
    {
        if ($value === 'never_connected') {
            $this->builder->whereNull('last_online_at');
        } elseif ($value === 'online') {
            $this->builder->where('last_online_at', '>=', now()->subMinutes(10));
        } elseif ($value === 'recently_offline') {
            $this->builder->whereBetween('last_online_at', [now()->subDay(), now()->subMinutes(10)]);
        } elseif ($value === 'offline') {
            $this->builder->where('last_online_at', '<', now()->subDay());
        }
    }

    public function attachmentState(?string $value)
    {
        $method = $value === 'attached' ? 'whereHas' : 'whereDoesntHave';
        $this->builder->{$method}('currentConnection');
    }

    public function vehicle(?string $value)
    {
        $vehicle = Vehicle::where('company_uuid', $this->session->get('company'))->where('public_id', $value)->first();
        $this->builder->whereHas('currentConnection', fn ($q) => $q->where('connector_uuid', $vehicle?->uuid ?? 'missing'));
    }

    public function vendor(?string $value)
    {
        $this->builder->whereIn('vendor_uuid', Vendor::where('company_uuid', $this->session->get('company'))->where('public_id', $value)->pluck('uuid'));
    }

    public function createdAt($value)
    {
        $this->dateFilter('created_at', $value);
    }

    public function updatedAt($value)
    {
        $this->dateFilter('updated_at', $value);
    }

    public function lastOnlineAt($value)
    {
        $this->dateFilter('last_online_at', $value);
    }

    private function dateFilter(string $column, $value): void
    {
        $range = Utils::dateRange($value);
        is_array($range) ? $this->builder->whereBetween($column, $range) : $this->builder->whereDate($column, $range);
    }
}
