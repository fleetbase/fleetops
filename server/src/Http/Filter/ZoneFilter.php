<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class ZoneFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function serviceArea(string $serviceAreaId)
    {
        $this->builder->where(function ($query) use ($serviceAreaId) {
            $query->where('service_area_uuid', $serviceAreaId);
            $query->orWhereHas('serviceArea', function ($query) use ($serviceAreaId) {
                $query->where('public_id', $serviceAreaId);
            });
        });
    }
}
