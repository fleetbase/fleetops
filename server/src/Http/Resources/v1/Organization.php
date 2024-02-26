<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\Organization;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;


class Order extends FleetbaseResource
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
            'id'                   => $this->when(Http::isInternalRequest(),  $this->public_id, Organization::collection($this->public_id)),
            'name'                => $this->when(Http::isInternalRequest(),  $this->name, Organization::collection($this-> name)), ];
    }

}