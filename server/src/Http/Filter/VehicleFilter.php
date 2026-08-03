<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;

class VehicleFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function query(?string $query)
    {
        $this->builder->search($query);
    }

    public function display_name(?string $display_name)
    {
        $this->builder->searchWhere(['year', 'make', 'model', 'plate_number'], $display_name);
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

    public function vehicleMake(?string $vehicleMake)
    {
        $this->builder->searchWhere('make', $vehicleMake);
    }

    public function vehicleModel(?string $vehicle_model)
    {
        $this->builder->searchWhere('model', $vehicle_model);
    }

    public function vehicleYear(?string $vehicleYear)
    {
        $this->builder->searchWhere('year', $vehicleYear);
    }

    public function driver(?string $driverId)
    {
        if ($driverId === 'unassigned') {
            $this->builder->whereDoesntHave('driver');

            return;
        }

        $this->builder->whereHas(
            'driver',
            function ($query) use ($driverId) {
                $query->where('uuid', $driverId);
            }
        );
    }

    public function vendor(?string $vendor)
    {
        if (!$vendor) {
            return;
        }

        $this->builder->whereIn('vendor_uuid', Vendor::query()
            ->where('company_uuid', $this->session->get('company'))
            ->where(function ($query) use ($vendor) {
                $query->where('public_id', $vendor);

                if (in_array('internal_id', (new Vendor())->getFillable())) {
                    $query->orWhere('internal_id', $vendor);
                }

                if (Http::isInternalRequest($this->request)) {
                    $query->orWhere('uuid', $vendor);
                }
            })
            ->pluck('uuid'));
    }

    public function driverUuid(?string $driverId)
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

    public function assignedFleet(string $assignedFleet)
    {
        if ($assignedFleet === 'false') {
            $this->builder->whereDoesntHave('fleets');
        }
    }

    public function telematicUuid(?string $telematic)
    {
        if (!$telematic) {
            return;
        }

        $this->builder->whereHas('devices', function ($query) use ($telematic) {
            $query->where('telematic_uuid', $telematic);
            $query->whereIn('attachable_type', ['fleet-ops:vehicle', Vehicle::class]);
        });
    }
}
