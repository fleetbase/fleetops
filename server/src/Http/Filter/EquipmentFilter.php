<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;

class EquipmentFilter extends Filter
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

    public function attachmentState(?string $state)
    {
        $state === 'attached' ? $this->builder->whereNotNull('equipable_uuid') : $this->builder->whereNull('equipable_uuid');
    }

    public function equipableType(?string $type)
    {
        $class = match ($type) {
            'vehicle', 'fleet-ops:vehicle' => Vehicle::class, 'trailer', 'fleet-ops:trailer' => Trailer::class, default => null,
        };
        $class ? $this->builder->where('equipable_type', $class) : $this->builder->whereRaw('0 = 1');
    }

    /**
     * Filter by the asset the equipment is equipped to. `equipable` is a morphTo relation,
     * which `whereHas` cannot traverse, so the identifier is resolved to the asset UUID
     * across every equipable model first. The public API filters by public id; the
     * console may also pass the asset UUID.
     */
    public function equipable($id)
    {
        $identifiers = array_values(array_filter(array_map('strval', (array) $id), 'strlen'));

        if (empty($identifiers)) {
            return;
        }

        $allowUuid = Http::isInternalRequest($this->request);
        $uuids     = [];

        foreach ([Vehicle::class, Trailer::class, Driver::class] as $modelClass) {
            $uuids = array_merge($uuids, $modelClass::query()
                ->where('company_uuid', $this->session->get('company'))
                ->where(function ($query) use ($identifiers, $allowUuid) {
                    $query->whereIn('public_id', $identifiers);

                    if ($allowUuid) {
                        $query->orWhereIn('uuid', $identifiers);
                    }
                })
                ->pluck('uuid')
                ->all());
        }

        $this->builder->whereIn('equipable_uuid', $uuids);
    }
}
