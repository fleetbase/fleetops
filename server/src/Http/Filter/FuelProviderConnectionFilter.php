<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class FuelProviderConnectionFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function provider(?string $provider)
    {
        $this->builder->where('provider', $provider);
    }

    public function status(?string $status)
    {
        $this->builder->where('status', $status);
    }

    public function environment(?string $environment)
    {
        $this->builder->where('environment', $environment);
    }
}
