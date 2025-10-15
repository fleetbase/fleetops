<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class CurrentJob extends FleetbaseResource
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
        return $this->withCustomFields([
            'id'           => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'         => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'    => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid' => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'internal_id'  => $this->internal_id,
            'payload'      => $this->when(Http::isInternalRequest(), new Payload($this->payload)),
            'type'         => $this->type,
            'status'       => $this->status,
            'meta'         => data_get($this, 'meta', Utils::createObject()),
            'updated_at'   => $this->updated_at,
            'created_at'   => $this->created_at,
        ]);
    }
}
