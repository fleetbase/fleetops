<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class AssetConnection extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'              => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'         => $this->when(Http::isInternalRequest(), $this->public_id),
            'relationship_type' => $this->relationship_type, 'position' => $this->position,
            'vehicle'           => $this->whenLoaded('vehicle', fn () => $this->vehicle ? ['id' => $this->vehicle->public_id, 'name' => $this->vehicle->display_name, 'plate_number' => $this->vehicle->plate_number] : null),
            'trailer'           => $this->whenLoaded('trailer', fn () => $this->trailer ? ['id' => $this->trailer->public_id, 'name' => $this->trailer->display_name, 'type' => $this->trailer->type, 'plate_number' => $this->trailer->plate_number] : null),
            'connected_at'      => $this->connected_at, 'disconnected_at' => $this->disconnected_at,
            'active'            => $this->disconnected_at === null, 'source' => $this->source, 'confidence' => $this->confidence,
            'notes'             => $this->notes, 'meta' => $this->meta ?? (object) [], 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
