<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Http\Filter\Concerns\ResolvesPublicRelationUuids;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;
use Fleetbase\Support\Http;

class FleetFilter extends Filter
{
    use ResolvesPublicRelationUuids;

    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'))->with(['serviceArea', 'zone']);
    }

    public function queryForPublic()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    /**
     * Free-text search across a fleet's own columns.
     *
     * Previously matched against a `user` relation, which Fleet does not have —
     * every `?query=` on the fleets endpoint raised a relation-not-found error
     * rather than returning results.
     */
    public function query(?string $searchQuery)
    {
        $this->builder->where(function ($query) use ($searchQuery) {
            $query->searchWhere(['name', 'task', 'public_id'], $searchQuery);
        });
    }

    public function parentsOnly(bool $parentsOnly = false)
    {
        if ($parentsOnly) {
            $this->builder->whereNull('parent_fleet_uuid');
        }
    }

    public function serviceArea(?string $serviceArea)
    {
        $this->builder->whereIn('service_area_uuid', $this->resolvePublicRelationUuids(ServiceArea::class, $serviceArea));
    }

    public function zone(?string $zone)
    {
        $this->builder->whereIn('zone_uuid', $this->resolvePublicRelationUuids(Zone::class, $zone));
    }

    public function parentFleet(?string $fleet)
    {
        $this->builder->whereIn('parent_fleet_uuid', $this->resolvePublicRelationUuids(Fleet::class, $fleet));
    }

    public function vendor(?string $vendor)
    {
        $this->builder->whereIn('vendor_uuid', $this->resolvePublicRelationUuids(Vendor::class, $vendor));
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

    public function task(?string $task)
    {
        $this->builder->searchWhere('task', $task);
    }

    public function name(?string $name)
    {
        $this->builder->searchWhere('name', $name);
    }

    public function status(string|array $status)
    {
        $status = Utils::arrayFrom($status);
        if ($status) {
            $this->builder->whereIn('status', $status);
        }
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
}
