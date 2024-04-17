<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class UserResource extends FleetbaseResource
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
        return [
            'id'                                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                               => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'                          => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'                               => $this->name,
            'username'                           => $this->username,
            'email'                              => $this->email,
            'phone'                              => $this->phone,
            'country'                            => data_get($this, 'country'),
            'avatar_url'                         => $this->avatar_url,
            'meta'                               => $this->meta,
            'updated_at'                         => $this->updated_at,
            'created_at'                         => $this->created_at,
        ];
    }

}