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

    public function queryForPublic()
    {
        $this->queryForInternal();
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
        $this->builder->where(function ($query) use ($vehicle) {
            $query->where('vehicle_uuid', $vehicle)
                ->orWhereIn('vehicle_uuid', \Fleetbase\FleetOps\Models\Vehicle::query()
                    ->where('company_uuid', $this->session->get('company'))
                    ->where('public_id', $vehicle)
                    ->pluck('uuid'));
        });
    }

    public function connection(?string $connection)
    {
        $this->builder->where(function ($query) use ($connection) {
            $query->where('fuel_provider_connection_uuid', $connection)
                ->orWhereIn('fuel_provider_connection_uuid', \Fleetbase\FleetOps\Models\FuelProviderConnection::query()
                    ->where('company_uuid', $this->session->get('company'))
                    ->where('public_id', $connection)
                    ->pluck('uuid'));
        });
    }

    public function driver(?string $driver)
    {
        $this->builder->where(function ($query) use ($driver) {
            $query->where('driver_uuid', $driver)
                ->orWhereIn('driver_uuid', \Fleetbase\FleetOps\Models\Driver::query()
                    ->where('company_uuid', $this->session->get('company'))
                    ->where('public_id', $driver)
                    ->pluck('uuid'));
        });
    }

    public function order(?string $order)
    {
        $this->builder->where(function ($query) use ($order) {
            $query->where('order_uuid', $order)
                ->orWhereIn('order_uuid', \Fleetbase\FleetOps\Models\Order::query()
                    ->where('company_uuid', $this->session->get('company'))
                    ->where('public_id', $order)
                    ->pluck('uuid'));
        });
    }

    public function fuelReport(?string $fuelReport)
    {
        $this->builder->where(function ($query) use ($fuelReport) {
            $query->where('fuel_report_uuid', $fuelReport)
                ->orWhereIn('fuel_report_uuid', \Fleetbase\FleetOps\Models\FuelReport::query()
                    ->where('company_uuid', $this->session->get('company'))
                    ->where('public_id', $fuelReport)
                    ->pluck('uuid'));
        });
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
