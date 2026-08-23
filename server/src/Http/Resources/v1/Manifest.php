<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Public projection of a Manifest — a driver's route for a day.
 *
 * A manifest is order-agnostic: it is a sequence of stops, which may belong to
 * several orders or to none the driver has ever seen as an order. That is why
 * this exposes the stops directly rather than making a consumer assemble them
 * from orders.
 *
 * Totals ride along because a driver wants to know what the day looks like
 * before starting it, and computing them client-side from the stop list gives a
 * different answer than the server's own.
 */
class Manifest extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'        => $this->when(Http::isInternalRequest(), $this->public_id),
            'status'           => $this->status,
            'scheduled_date'   => $this->scheduled_date,
            'started_at'       => $this->started_at,
            'completed_at'     => $this->completed_at,
            'total_distance_m' => $this->total_distance_m,
            'total_duration_s' => $this->total_duration_s,
            'stop_count'       => $this->stop_count,
            'completed_stops'  => $this->completed_stops,
            'pending_stops'    => $this->pending_stops,
            'driver_name'      => $this->driver_name,
            'vehicle_name'     => $this->vehicle_name,
            'notes'            => $this->notes,
            // Present only when the manifest was loaded with them, so a list
            // endpoint stays a list.
            'stops'            => ManifestStop::collection($this->whenLoaded('stops')),
            'updated_at'       => $this->updated_at,
            'created_at'       => $this->created_at,
        ];
    }
}
