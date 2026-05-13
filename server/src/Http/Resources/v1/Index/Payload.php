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
        $pickup     = $this->index_pickup_place;
        $dropoff    = $this->index_dropoff_place;

        return [
            'id'           => $this->when($isInternal, $this->id, $this->public_id),
            'uuid'         => $this->when($isInternal, $this->uuid),
            'public_id'    => $this->when($isInternal, $this->public_id),
            'company_uuid' => $this->when($isInternal, $this->company_uuid),
            'pickup_uuid'  => $this->when($isInternal, $this->pickup_uuid),
            'dropoff_uuid' => $this->when($isInternal, $this->dropoff_uuid),
            'return_uuid'  => $this->when($isInternal, $this->return_uuid),

            // Minimal pickup - only what's displayed in the table
            'pickup'     => $this->when($pickup, function () use ($pickup) {
                return new Place($pickup);
            }),

            // Minimal dropoff - only what's displayed in the table
            'dropoff'    => $this->when($dropoff, function () use ($dropoff) {
                return new Place($dropoff);
            }),

            // Entity count instead of full entities
            'entities_count' => $this->whenLoaded('entities', function () {
                return $this->entities->count();
            }, $this->entities()->count()),

            // Waypoint count for lightweight route-shape summaries.
            'waypoints_count' => $this->whenLoaded('waypoints', function () {
                return $this->waypoints->count();
            }, $this->waypoints()->count()),

            'type'       => $this->type,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
