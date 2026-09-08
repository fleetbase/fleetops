<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Models\Category;
use Fleetbase\Support\Http;

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

    public function trailerType($value)
    {
        $this->whereOneOf('type', $value);
    }

    public function status($value)
    {
        $this->whereOneOf('status', $value);
    }

    public function trailerMake(?string $value)
    {
        $this->builder->searchWhere('make', $value);
    }

    public function trailerModel(?string $value)
    {
        $this->builder->searchWhere('model', $value);
    }

    public function trailerYear($value)
    {
        $this->whereOneOf('year', $value);
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

    public function bodyType(?string $value)
    {
        $this->builder->searchWhere('body_type', $value);
    }

    public function length($value)
    {
        $this->whereOneOf('length', $value);
    }

    public function axleCount($value)
    {
        $this->whereOneOf('axle_count', $value);
    }

    public function gvwr($value)
    {
        $this->whereOneOf('gvwr', $value);
    }

    public function payloadCapacity($value)
    {
        $this->whereOneOf('payload_capacity', $value);
    }

    public function ownershipType($value)
    {
        $this->whereOneOf('ownership_type', $value);
    }

    public function refrigerated($value)
    {
        $this->builder->where('refrigerated', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function devicesCount($value)
    {
        $this->builder->has('devices', '=', (int) $value);
    }

    public function equipmentCount($value)
    {
        $this->builder->has('equipments', '=', (int) $value);
    }

    /**
     * Connectivity is derived from `last_online_at`, mirroring
     * Trailer::getConnectivityStatusAttribute(). Multiple states combine with OR.
     */
    public function connectivityStatus($value)
    {
        $states = array_values(array_filter(array_map('strval', (array) $value), 'strlen'));

        if (empty($states)) {
            return;
        }

        $this->builder->where(function ($query) use ($states) {
            foreach ($states as $state) {
                $query->orWhere(function ($query) use ($state) {
                    match ($state) {
                        'never_connected'  => $query->whereNull('last_online_at'),
                        'online'           => $query->where('last_online_at', '>=', now()->subMinutes(10)),
                        'recently_offline' => $query->whereBetween('last_online_at', [now()->subDay(), now()->subMinutes(10)]),
                        'offline'          => $query->where('last_online_at', '<', now()->subDay()),
                        default            => $query->whereRaw('0 = 1'),
                    };
                });
            }
        });
    }

    public function attachmentState($value)
    {
        $states = array_unique(array_values(array_filter(array_map('strval', (array) $value), 'strlen')));

        // Asking for both states is the same as not filtering.
        if (empty($states) || count($states) > 1) {
            return;
        }

        $method = $states[0] === 'attached' ? 'whereHas' : 'whereDoesntHave';
        $this->builder->{$method}('currentConnection');
    }

    public function vehicle($value)
    {
        $uuids = $this->resolveRelationUuids(Vehicle::class, $value);

        $this->builder->whereHas('currentConnection', fn ($query) => $query->whereIn('connector_uuid', $uuids));
    }

    public function vendor($value)
    {
        $this->builder->whereIn('vendor_uuid', $this->resolveRelationUuids(Vendor::class, $value));
    }

    /**
     * Trailers with any of the given devices installed.
     */
    public function device($value)
    {
        $uuids = $this->resolveRelationUuids(Device::class, $value);

        $this->builder->whereHas('devices', fn ($query) => $query->whereIn('uuid', $uuids));
    }

    public function category($value)
    {
        $this->builder->whereIn('category_uuid', $this->resolveRelationUuids(Category::class, $value, false));
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

    public function purchasedAt($value)
    {
        $this->dateFilter('purchased_at', $value);
    }

    /**
     * Console multi-option filters submit arrays; the public API submits scalars.
     */
    private function whereOneOf(string $column, $value): void
    {
        $values = array_values(array_filter(array_map('strval', (array) $value), 'strlen'));

        if (empty($values)) {
            return;
        }

        count($values) === 1 ? $this->builder->where($column, $values[0]) : $this->builder->whereIn($column, $values);
    }

    /**
     * Resolve related record identifiers to UUIDs. The console filters by the record's
     * UUID; the public API filters by public id (or a vehicle's internal id).
     */
    private function resolveRelationUuids(string $modelClass, $identifiers, bool $scopeToCompany = true): array
    {
        $identifiers = array_values(array_filter(array_map('strval', (array) $identifiers), 'strlen'));

        if (empty($identifiers)) {
            return [];
        }

        $instance = new $modelClass();
        $query    = $modelClass::query()->where(function ($query) use ($identifiers, $instance) {
            $query->whereIn('public_id', $identifiers);

            if (in_array('internal_id', $instance->getFillable(), true)) {
                $query->orWhereIn('internal_id', $identifiers);
            }

            if (Http::isInternalRequest($this->request)) {
                $query->orWhereIn('uuid', $identifiers);
            }
        });

        if ($scopeToCompany) {
            $query->where('company_uuid', $this->session->get('company'));
        }

        return $query->pluck('uuid')->all();
    }

    private function dateFilter(string $column, $value): void
    {
        $range = Utils::dateRange($value);

        is_array($range) ? $this->builder->whereBetween($column, $range) : $this->builder->whereDate($column, $range);
    }
}
