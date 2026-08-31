<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

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
            'place'                => new Place($this->whenLoaded('place')),
            'order'                => $this->when($this->relationLoaded('order') && $this->order, fn () => [
                'id'              => data_get($this->order, 'public_id'),
                'tracking_number' => data_get($this->order, 'trackingNumber.tracking_number'),
                'status'          => data_get($this->order, 'status'),
            ]),
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ];
    }
}
