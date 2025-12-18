<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Customer resource for index views.
 * Handles polymorphic customer types (Contact, Vendor, etc.) with minimal data.
 */
class Customer extends FleetbaseResource
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
            'phone'      => $this->phone ?? null,
            'email'      => $this->email ?? null,
        ];
    }
}
