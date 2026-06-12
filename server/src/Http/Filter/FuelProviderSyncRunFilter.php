<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\Http\Filter\Filter;

class FuelProviderSyncRunFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function provider(?string $provider)
    {
        $this->builder->where('provider', $provider);
    }

    public function status(?string $status)
    {
        $this->builder->where('status', $status);
    }

    public function connection(?string $connection)
    {
        $this->builder->where('fuel_provider_connection_uuid', $connection);
    }
}
