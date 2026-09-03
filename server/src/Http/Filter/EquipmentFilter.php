<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Filter\Filter;

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

    public function equipable(?string $id)
    {
        $this->builder->whereHas('equipable', fn ($query) => $query->where('public_id', $id)->where('company_uuid', $this->session->get('company')));
    }
}
