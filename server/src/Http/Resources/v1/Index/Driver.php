<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Driver resource for index views.
 * Only includes essential identification and display information.
 */
class Driver extends FleetbaseResource
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
            'id'         => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'       => $this->when($isInternal, $this->uuid),
            'public_id'  => $this->when($isInternal, $this->public_id),
            'name'       => $this->name,
            'phone'      => $this->phone,
            'photo_url'  => $this->photo_url,
            'status'     => $this->status,
        ];
    }
}
