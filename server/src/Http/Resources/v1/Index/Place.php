<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Place resource for index views.
 * Only includes name, address, and location coordinates.
 */
class Place extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $isInternal = Http::isInternalRequest();

        return [
            'id'           => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'         => $this->when($isInternal, $this->uuid),
            'public_id'    => $this->when($isInternal, $this->public_id),
            'company_uuid' => $this->when($isInternal, $this->company_uuid),
            'owner_uuid'   => $this->when($isInternal, $this->owner_uuid),
            'owner_type'   => $this->when($isInternal, $this->owner_type),
            'name'         => $this->name,
            'street1'      => $this->street1,
            'city'         => $this->city,
            'country'      => $this->country,
            'location'     => Utils::getPointFromMixed($this->location),
        ];
    }
}
