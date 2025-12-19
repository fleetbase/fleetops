<?php

namespace Fleetbase\FleetOps\Http\Resources\v1\Index;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Lightweight Payload resource for index views.
 * Only includes essential pickup/dropoff information and entity count.
 */
class Payload extends FleetbaseResource
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
            'pickup_uuid'  => $this->when($isInternal, $this->pickup_uuid),
            'dropoff_uuid' => $this->when($isInternal, $this->dropoff_uuid),
            'return_uuid'  => $this->when($isInternal, $this->return_uuid),

            // Minimal pickup - only what's displayed in the table
            'pickup'     => $this->whenLoaded('pickup', function () {
                return new Place($this->pickup);
            }),

            // Minimal dropoff - only what's displayed in the table
            'dropoff'    => $this->whenLoaded('dropoff', function () {
                return new Place($this->dropoff);
            }),

            // Entity count instead of full entities
            'entities_count' => $this->whenLoaded('entities', function () {
                return $this->entities->count();
            }),

            // Waypoint count instead of full waypoints
            'waypoints_count' => $this->whenLoaded('waypoints', function () {
                return $this->waypoints->count();
            }),

            'type'       => $this->type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
