<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Concerns\ResolvesPublicRelationFields;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Fleet extends FleetbaseResource
{
    use ResolvesPublicRelationFields;

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $with = $this->requestedRelations($request);

        if ($with !== []) {
            $this->load($with);

            if (in_array('subfleets', $with, true)) {
                if (in_array('drivers', $with, true)) {
                    $this->loadMissing('subFleets.drivers');
                }

                if (in_array('vehicles', $with, true)) {
                    $this->loadMissing('subFleets.vehicles');
                }
            }
        }

        return $this->withCustomFields([
            'id'                    => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                  => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'             => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'                  => $this->name,
            'color'                 => $this->color ?? null,
            'task'                  => $this->task ?? null,
            'status'                => $this->status ?? null,
            'photo_url'             => $this->photo_url,
            // Closures, not values: `when()` evaluates a plain second argument
            // eagerly, so each of these counts ran a query on every public
            // request that then discarded the result.
            'drivers_count'         => $this->when(Http::isInternalRequest(), fn () => $this->drivers_count),
            'drivers_online_count'  => $this->when(Http::isInternalRequest(), fn () => $this->drivers_online_count),
            'vehicles_count'        => $this->when(Http::isInternalRequest(), fn () => $this->vehicles_count),
            'vehicles_online_count' => $this->when(Http::isInternalRequest(), fn () => $this->vehicles_online_count),
            'service_area'          => $this->publicRelationField('serviceArea', 'service_area_uuid', $with, fn () => new ServiceArea($this->serviceArea)),
            'zone'                  => $this->publicRelationField('zone', 'zone_uuid', $with, fn () => new Zone($this->zone)),
            'vendor'                => $this->publicRelationField('vendor', 'vendor_uuid', $with, fn () => new Vendor($this->vendor)),
            'parent_fleet'          => $this->publicRelationField('parentFleet', 'parent_fleet_uuid', $with, fn () => new ParentFleet($this->parentFleet)),
            'subfleets'             => $this->whenLoaded('subFleets', fn () => SubFleet::collection($this->subFleets)),
            'drivers'               => $this->whenLoaded('drivers', fn () => Driver::collection($this->drivers()->with(Http::isInternalRequest() || $request->has('with.jobs') ? ['jobs'] : [])->get())),
            'vehicles'              => $this->whenLoaded('vehicles', fn () => Vehicle::collection($this->vehicles)),
            'updated_at'            => $this->updated_at,
            'created_at'            => $this->created_at,
        ]);
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'           => $this->public_id,
            'name'         => $this->name,
            'color'        => $this->color ?? null,
            'task'         => $this->task ?? null,
            'status'       => $this->status ?? null,
            'parent_fleet' => $this->when($this->parentFleet, data_get($this, 'parentFleet.public_id')),
            'service_area' => $this->when($this->serviceArea, data_get($this, 'serviceArea.public_id')),
            'zone'         => $this->when($this->zone, data_get($this, 'zone.public_id')),
            'vendor'       => $this->when($this->vendor, data_get($this, 'vendor.public_id')),
            'updated_at'   => $this->updated_at,
            'created_at'   => $this->created_at,
        ];
    }
}
