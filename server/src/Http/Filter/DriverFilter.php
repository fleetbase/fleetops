<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\Concerns\ResolvesPublicRelationUuids;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Models\Company;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

class DriverFilter extends Filter
{
    use ResolvesPublicRelationUuids;

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

    /**
     * Match a driver by the identifier the operator's own system uses.
     *
     * Two callers, two meanings. The Fleet-Ops console types into a search box
     * and expects `VEH-10` to find `VEH-100`; an importer asks whether `VEH-10`
     * exists and must not be told yes because `VEH-100` does. A partial match
     * there produces a duplicate on every rerun.
     *
     * Core's ordinary fillable-column filtering is already equality — this
     * method exists only because the console needs the looser behaviour, so it
     * is the console that gets the exception. An unknown request context is
     * treated as public: exact is the safe default of the two.
     */
    public function internalId(?string $internalId)
    {
        if (Http::isInternalRequest($this->request)) {
            $this->builder->searchWhere('internal_id', $internalId);

            return;
        }

        $this->builder->where('internal_id', '=', $internalId);
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

    /**
     * A public id identifies exactly one record, so a public lookup matches it
     * exactly. The console keeps the partial search its id column filter box
     * has always had.
     */
    public function publicId(?string $publicId)
    {
        if (Http::isInternalRequest($this->request)) {
            $this->builder->searchWhere('public_id', $publicId);

            return;
        }

        $this->builder->where('public_id', '=', $publicId);
    }

    public function facilitator(string $facilitator)
    {
        $this->builder->whereIn('vendor_uuid', $this->resolvePublicRelationUuids(Vendor::class, $facilitator));
    }

    public function vehicle(string $vehicle)
    {
        if ($vehicle === 'unassigned') {
            $this->builder->whereNull('vehicle_uuid');

            return;
        }

        // The console passes a uuid; the public API passes a public or internal id.
        if (Str::isUuid($vehicle) && Http::isInternalRequest($this->request)) {
            $this->builder->where('vehicle_uuid', $vehicle);

            return;
        }

        $vehicleUuids = $this->resolvePublicRelationUuids(Vehicle::class, $vehicle);

        if ($vehicleUuids !== []) {
            $this->builder->whereIn('vehicle_uuid', $vehicleUuids);

            return;
        }

        // Fall back to a search so a partial plate or model still narrows a list.
        $this->builder->whereHas(
            'vehicle',
            function ($query) use ($vehicle) {
                $query->search($vehicle);
            }
        );
    }

    public function driversLicenseNumber(?string $driversLicenseNumber)
    {
        $this->builder->searchWhere('drivers_license_number', $driversLicenseNumber);
    }

    /**
     * `phone` is an accessor sourced from the linked user, not a relation — the
     * previous `whereHas('phone')` asked Eloquent for a relation that does not
     * exist and raised a 500 for every caller of `?phone=`.
     */
    public function phone(string $phone)
    {
        $this->builder->whereHas(
            'user',
            function ($query) use ($phone) {
                $query->searchWhere('phone', $phone);
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
        $fleetUuids = $this->resolvePublicRelationUuids(Fleet::class, $fleet);

        $this->builder->whereHas(
            'fleets',
            function ($q) use ($fleetUuids) {
                $q->whereIn('fleet_uuid', $fleetUuids);
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
        if (Utils::isCoordinatesStrict($nearby)) {
            $location = Utils::getPointFromMixed($nearby);

            $this->builder->whereNotNull('location')->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
            $this->builder->distanceSphere('location', $location, $distance);
            $this->builder->distanceSphereValue('location', $location);

            // Update so additional nearby queries are not added
            $addedNearbyQuery = true;
        }

        // if is a string like address string
        if ($addedNearbyQuery === false && is_string($nearby)) {
            $place = Place::createFromMixed($nearby, [], false);

            if ($place instanceof Place) {
                $this->builder->whereNotNull('location')->whereRaw('
                ST_Y(location) BETWEEN -90 AND 90
                AND ST_X(location) BETWEEN -180 AND 180
                AND NOT (ST_X(location) = 0 AND ST_Y(location) = 0)
            ');
                $this->builder->distanceSphere('location', $place->location, $distance);
                $this->builder->distanceSphereValue('location', $place->location);

                // Update so additional nearby queries are not added
                $addedNearbyQuery = true;
            }
        }
    }
}
