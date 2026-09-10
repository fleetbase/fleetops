<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * A public inspection link, as the console lists it.
 *
 * `state` is the computed one — active, expired, used or revoked — not the
 * stored `status`, because a link that has run out of time still says
 * `active` in the column. `url` is the link itself, present only for links
 * minted since the token has been kept.
 */
class InspectionLink extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public function toArray($request)
    {
        $internal = Http::isInternalRequest();

        return [
            'id'             => $this->when($internal, $this->id, $this->public_id),
            'uuid'           => $this->when($internal, $this->uuid),
            'public_id'      => $this->when($internal, $this->public_id),
            'path'           => $this->path,
            'state'          => $this->state,
            'status'         => $this->status,
            'single_use'     => (bool) $this->single_use,
            'driver'         => $this->driver ? [
                'id'   => $this->driver->public_id,
                'name' => $this->driver->name,
            ] : null,
            'vehicle'        => $this->vehicle ? [
                'id'   => $this->vehicle->public_id,
                'name' => $this->vehicle->display_name ?? $this->vehicle->name,
            ] : null,
            'created_by'     => $this->createdBy ? [
                'id'   => $this->createdBy->public_id,
                'name' => $this->createdBy->name,
            ] : null,
            'expires_at'     => $this->expires_at,
            'last_viewed_at' => $this->last_viewed_at,
            'used_at'        => $this->used_at,
            'created_at'     => $this->created_at,
        ];
    }
}
