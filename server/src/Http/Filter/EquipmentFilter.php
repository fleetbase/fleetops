<?php

namespace Fleetbase\FleetOps\Http\Filter;

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
}
