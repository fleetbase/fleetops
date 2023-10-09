<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\Http\Filter\Filter;
use Fleetbase\FleetOps\Support\Utils;

class VehicleFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }

    public function name(?string $name)
    {
        $this->builder->searchWhere(['year', 'model_make_display', 'make', 'model', 'trim', 'plate_number'], $name);
    }

    public function vin(?string $vin)
    {
        $this->builder->searchWhere('vin', $vin);
    }

    public function publicId(?string $publicIc)
    {
        $this->builder->searchWhere('public_id', $publicIc);
    }

    public function plateNumber(?string $plateNumber)
    {
        $this->builder->searchWhere('plate_number', $plateNumber);
    }

    public function vehicleMake(?string $make)
    {
        $this->builder->searchWhere('make', $make);
    }

    public function vehicleModel(?string $model)
    {
        $this->builder->searchWhere('model', $model);
    }

    public function driver(?string $driverId)
    {
        $this->builder->whereHas(
            'driver',
            function ($query) use ($driverId) {
                $query->where('uuid', $driverId);
            }
        );
    }

    public function createdAt($createdAt) 
    {
        $createdAt = Utils::dateRange($createdAt);

        if (is_array($createdAt)) {
            $this->builder->whereBetween('created_at', $createdAt);
        } else {
            $this->builder->whereDate('created_at', $createdAt);
        }
    }

    public function updatedAt($updatedAt) 
    {
        $updatedAt = Utils::dateRange($updatedAt);

        if (is_array($updatedAt)) {
            $this->builder->whereBetween('updated_at', $updatedAt);
        } else {
            $this->builder->whereDate('updated_at', $updatedAt);
        }
    }
    
    public function fleet(string $fleet)
    {
        $this->builder->whereHas(
            'fleets',
            function ($q) use ($fleet) {
                $q->where('fleet_uuid', $fleet);
            }
        );
    }
}
