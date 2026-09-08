<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\Concerns\ResolvesPublicRelationUuids;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;

class VehicleFilter extends Filter
{
    use ResolvesPublicRelationUuids;

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

    /**
     * Match a vehicle by the identifier the operator's own system uses.
     *
     * `internal_id` is the column an importer keys on to decide whether a
     * vehicle already exists, and it was the one identifier the filter did not
     * support — so every lookup fell through to a create.
     */
    /**
     * Match a vehicle by the identifier the operator's own system uses.
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
    public function internalId($internalId)
    {
        // The console's tag-input filter sends several ids at once (batch scans);
        // any list is an exact match on each id.
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $internalId)), 'strlen'));

        if (count($ids) > 1) {
            $this->builder->whereIn('internal_id', $ids);

            return;
        }

        if (Http::isInternalRequest($this->request)) {
            $this->builder->searchWhere('internal_id', $internalId);

            return;
        }

        $this->builder->where('internal_id', '=', $internalId);
    }

    public function vin(?string $vin)
    {
        $this->builder->searchWhere('vin', $vin);
    }

    /**
     * A public id identifies exactly one record, so a public lookup matches it
     * exactly. The console keeps the partial search its id column filter box
     * has always had.
     */
    public function publicId(?string $publicIc)
    {
        if (Http::isInternalRequest($this->request)) {
            $this->builder->searchWhere('public_id', $publicIc);

            return;
        }

        $this->builder->where('public_id', '=', $publicIc);
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

    public function driver($driverId)
    {
        if ($driverId === 'unassigned') {
            $this->builder->whereDoesntHave('driver');

            return;
        }

        $driverUuids = $this->resolvePublicRelationUuids(Driver::class, $driverId);

        $this->builder->whereHas(
            'driver',
            function ($query) use ($driverUuids) {
                $query->whereIn('uuid', $driverUuids);
            }
        );
    }

    /**
     * Vehicles currently towing any of the given trailers (public id, internal id or,
     * for the console, uuid). Several trailers combine with OR.
     */
    public function trailer($trailer)
    {
        $uuids = $this->resolvePublicRelationUuids(Trailer::class, $trailer);

        $this->builder->whereHas('currentTrailers', function ($query) use ($uuids) {
            $query->whereIn('assets.uuid', $uuids);
        });
    }

    /**
     * Vehicles with any of the given devices installed.
     */
    public function device($device)
    {
        $uuids = $this->resolvePublicRelationUuids(Device::class, $device);

        $this->builder->whereHas('devices', function ($query) use ($uuids) {
            $query->whereIn('uuid', $uuids);
        });
    }

    public function hasTrailer($value)
    {
        $this->whereRelationPresence('currentTrailers', $value);
    }

    public function hasDriver($value)
    {
        $this->whereRelationPresence('driver', $value);
    }

    public function hasDevice($value)
    {
        $this->whereRelationPresence('devices', $value);
    }

    /**
     * `true` keeps vehicles that have the relation, `false` those that do not, and
     * anything else (an empty select) leaves the query untouched.
     */
    protected function whereRelationPresence(string $relation, $value): void
    {
        $presence = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($presence === true) {
            $this->builder->whereHas($relation, fn ($query) => $query);
        } elseif ($presence === false) {
            $this->builder->whereDoesntHave($relation);
        }
    }

    public function vendor($vendor)
    {
        if (!$vendor) {
            return;
        }

        $this->builder->whereIn('vendor_uuid', $this->resolvePublicRelationUuids(Vendor::class, $vendor));
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
        $fleetUuids = $this->resolvePublicRelationUuids(Fleet::class, $fleet);

        $this->builder->whereHas(
            'fleets',
            function ($q) use ($fleetUuids) {
                $q->whereIn('fleet_uuid', $fleetUuids);
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
