<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Illuminate\Support\Str;

class FuelProviderTransactionFilter extends Filter
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

    public function syncStatus($status)
    {
        if (Str::contains($status, ',')) {
            $status = explode(',', $status);
        }

        if (is_array($status)) {
            $this->builder->whereIn('sync_status', $status);
        } else {
            $this->builder->where('sync_status', $status);
        }
    }

    public function vehicle(?string $vehicle)
    {
        $this->builder->where('vehicle_uuid', $vehicle);
    }

    public function connection(?string $connection)
    {
        $this->builder->where('fuel_provider_connection_uuid', $connection);
    }

    public function transactionAt($transactionAt)
    {
        $transactionAt = Utils::dateRange($transactionAt);

        if (is_array($transactionAt)) {
            $this->builder->whereBetween('transaction_at', $transactionAt);
        } else {
            $this->builder->whereDate('transaction_at', $transactionAt);
        }
    }
}
