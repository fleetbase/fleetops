<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Models\Company;
use Illuminate\Support\Str;

class DriverFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where(
            function ($query) {
                $query->where('company_uuid', $this->session->get('company'));
                $query->whereHas('user');
            }
        );
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function query(?string $searchQuery)
    {
        $this->builder->where(function ($query) use ($searchQuery) {
            $query->orWhereHas(
                'user',
                function ($query) use ($searchQuery) {
                    $query->searchWhere(['name', 'email', 'phone'], $searchQuery);
                }
            );

            $query->orWhere(
                function ($query) use ($searchQuery) {
                    $query->searchWhere(['drivers_license_number'], $searchQuery);
                }
            );
        });
    }

    public function internalId(?string $internalId)
    {
        $this->builder->searchWhere('internal_id', $internalId);
    }

    public function name(?string $name)
    {
        $this->builder->whereHas(
            'user',
            function ($query) use ($name) {
                $query->searchWhere('name', $name);
            }
        );
    }

    public function publicId(?string $publicId)
    {
        $this->builder->searchWhere('public_id', $publicId);
    }

    public function facilitator(string $facilitator)
    {
        $this->builder->where('vendor_uuid', $facilitator);
    }

    public function vehicle(string $vehicle)
    {
        if (Str::isUuid($vehicle)) {
            $this->builder->where('vehicle_uuid', $vehicle);
        } else {
            $this->builder->whereHas(
                'vehicle',
                function ($query) use ($vehicle) {
                    $query->search($vehicle);
                }
            );
        }
    }

    public function driversLicenseNumber(?string $driversLicenseNumber)
    {
        $this->builder->searchWhere('drivers_license_number', $driversLicenseNumber);
    }

    public function phone(string $phone)
    {
        $this->builder->whereHas(
            'phone',
            function ($query) use ($phone) {
                $query->search($phone);
            }
        );
    }

    public function country(?string $country)
    {
        if (strpos($country, ',') !== false) {
            $this->builder->whereIn('country', explode(',', $country));
        } else {
            $this->builder->searchWhere('country', $country);
        }
    }

    public function status(string|array $status)
    {
        $status = Utils::arrayFrom($status);
        if ($status) {
            $this->builder->whereIn('status', $status);
        }
    }

    public function vendor(string $vendor)
    {
        $this->facilitator($vendor);
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

    public function nearby($nearby)
    {
        $distance         = $this->request->input('radius'); // default in meters
        $company          = Company::currentSession();
        $addedNearbyQuery = false;

        if (!$distance && $company) {
            $distance = $company->getOption('fleetops.adhoc_distance', 6000);
        }

        if (!$distance) {
            $distance = 6000;
        }

        // if wants to find nearby place or coordinates
        if (Utils::isCoordinates($nearby)) {
            $location = Utils::getPointFromMixed($nearby);

            $this->builder->distanceSphere('location', $location, $distance);
            $this->builder->distanceSphereValue('location', $location);

            // Update so additional nearby queries are not added
            $addedNearbyQuery = true;
        }

        // if is a string like address string
        if ($addedNearbyQuery === false && is_string($nearby)) {
            $place = Place::createFromMixed($nearby, [], false);

            if ($nearby instanceof Place) {
                $this->builder->distanceSphere('location', $place->location, $distance);
                $this->builder->distanceSphereValue('location', $place->location);

                // Update so additional nearby queries are not added
                $addedNearbyQuery = true;
            }
        }
    }
}
