<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\ManifestStopRole;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

/**
 * Public projection of a ManifestStop.
 *
 * Carries the place inline rather than as an id. A driver app rendering a route
 * needs a name and a coordinate for every stop, and making it resolve each one
 * separately turns a route of twenty stops into twenty-one requests.
 *
 * `sequence` is the order to drive them in, and is the field a re-sequence
 * rewrites.
 */
class ManifestStop extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        // What this stop is to its order (pickup, waypoint, drop-off), where it falls
        // among the order's points, and the window and handover rules that apply here.
        $role = $this->relationLoaded('order') && $this->order ? ManifestStopRole::describe($this->resource) : [];

        return [
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'status'               => $this->status,
            'sequence'             => $this->sequence,
            'estimated_arrival'    => $this->estimated_arrival,
            'actual_arrival'       => $this->actual_arrival,
            'distance_from_prev_m' => $this->distance_from_prev_m,
            'duration_from_prev_s' => $this->duration_from_prev_s,
            'type'                 => $role['type'] ?? null,
            'position'             => $role['position'] ?? null,
            'position_total'       => $role['position_total'] ?? null,
            'waypoint_id'          => $role['waypoint_id'] ?? null,
            'waypoint_index'       => $role['waypoint_index'] ?? null,
            'waypoint_total'       => $role['waypoint_total'] ?? null,
            'time_window_start'    => $role['time_window_start'] ?? null,
            'time_window_end'      => $role['time_window_end'] ?? null,
            'service_time'         => $role['service_time'] ?? null,
            'notes'                => $role['notes'] ?? null,
            'pod_required'         => $role['pod_required'] ?? null,
            'pod_method'           => $role['pod_method'] ?? null,
            // Free-form notes the app writes when a stop is skipped or changed.
            'meta'                 => $this->meta,
            'place'                => new Place($this->whenLoaded('place')),
            'order'                => $this->when($this->relationLoaded('order') && $this->order, fn () => [
                'id'              => data_get($this->order, 'public_id'),
                'tracking_number' => data_get($this->order, 'trackingNumber.tracking_number'),
                'status'          => data_get($this->order, 'status'),
                'internal_id'     => data_get($this->order, 'internal_id'),
                'type'            => data_get($this->order, 'type'),
            ]),
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ];
    }
}
