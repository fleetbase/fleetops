<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Concerns\ResolvesPublicRelationFields;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

/**
 * An effective-dated towing connection between a Vehicle (connector) and a
 * Trailer (connected asset).
 */
class AssetConnection extends FleetbaseResource
{
    use ResolvesPublicRelationFields;

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $internal = Http::isInternalRequest();

        return [
            'id'                => $this->when($internal, $this->id, $this->public_id),
            'uuid'              => $this->when($internal, $this->uuid),
            'public_id'         => $this->when($internal, $this->public_id),
            'company_uuid'      => $this->when($internal, $this->company_uuid),
            'connector_type'    => $this->when($internal, Utils::toEmberResourceType($this->connector_type)),
            'connector_uuid'    => $this->when($internal, $this->connector_uuid),
            'connected_type'    => $this->when($internal, Utils::toEmberResourceType($this->connected_type)),
            'connected_uuid'    => $this->when($internal, $this->connected_uuid),
            'relationship_type' => $this->relationship_type,
            'position'          => $this->position,
            // Identifiers are always present so a connection can be followed back to both
            // sides even when the related records were not eager loaded.
            'vehicle_id'        => $this->publicIdForRelation('vehicle', 'connector_uuid'),
            'trailer_id'        => $this->publicIdForRelation('trailer', 'connected_uuid'),
            'vehicle'           => $this->whenLoaded('vehicle', fn () => $this->vehicleRepresentation($internal)),
            'trailer'           => $this->whenLoaded('trailer', fn () => $this->trailerRepresentation($internal)),
            'connected_at'      => $this->connected_at,
            'disconnected_at'   => $this->disconnected_at,
            'active'            => $this->disconnected_at === null,
            'source'            => $this->source,
            'confidence'        => $this->confidence,
            'notes'             => $this->notes,
            'meta'              => data_get($this, 'meta', Utils::createObject()),
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    /**
     * Only invoked through whenLoaded(), which already yields null for an absent record.
     */
    protected function vehicleRepresentation(bool $internal): mixed
    {
        $vehicle = $this->vehicle;

        if ($internal) {
            return Resolve::httpResourceForModel($vehicle);
        }

        return [
            'id'           => $vehicle->public_id,
            'name'         => $vehicle->display_name,
            'plate_number' => $vehicle->plate_number,
        ];
    }

    protected function trailerRepresentation(bool $internal): mixed
    {
        $trailer = $this->trailer;

        if ($internal) {
            return Resolve::httpResourceForModel($trailer);
        }

        return [
            'id'           => $trailer->public_id,
            'name'         => $trailer->display_name,
            'type'         => $trailer->type,
            'plate_number' => $trailer->plate_number,
        ];
    }
}
