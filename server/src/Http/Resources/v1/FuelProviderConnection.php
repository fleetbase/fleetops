<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class FuelProviderConnection extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'            => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'       => $this->when(Http::isInternalRequest(), $this->public_id),
            'provider'        => $this->provider,
            'name'            => $this->name,
            'environment'     => $this->environment,
            'status'          => $this->status,
            'sync_settings'   => $this->sync_settings,
            'last_sync_state' => $this->last_sync_state,
            'last_synced_at'  => $this->last_synced_at,
            'last_tested_at'  => $this->last_tested_at,
            'last_error'      => $this->last_error,
            'meta'            => $this->meta,
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
