<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class ServiceRateFee extends FleetbaseResource
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
            'id'                      => $this->when(Http::isInternalRequest(), $this->id),
            'uuid'                    => $this->when(Http::isInternalRequest(), $this->uuid),
            'service_rate_uuid'       => $this->when(Http::isInternalRequest(), $this->service_rate_uuid),
            'service_area_uuid'       => $this->when(Http::isInternalRequest(), $this->service_area_uuid),
            'zone_uuid'               => $this->when(Http::isInternalRequest(), $this->zone_uuid),
            'service_area'            => $this->whenLoaded('serviceArea', fn () => new ServiceArea($this->serviceArea)),
            'zone'                    => $this->whenLoaded('zone', fn () => new Zone($this->zone)),
            'label'                   => $this->label,
            'priority'                => $this->priority,
            'is_fallback'             => (bool) $this->is_fallback,
            'fee'                     => $this->fee,
            'currency'                => $this->currency,
            'min'                     => $this->min,
            'max'                     => $this->max,
            'unit'                    => $this->unit,
            'distance'                => $this->distance,
            'distance_unit'           => $this->distance_unit,
            'updated_at'              => $this->updated_at,
            'created_at'              => $this->created_at,
        ];
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'fee'             => $this->fee,
            'currency'        => $this->currency,
            'size'            => $this->size,
            'length'          => $this->length,
            'height'          => $this->height,
            'dimensions_unit' => $this->dimensions_unit,
            'weight'          => $this->weight,
            'weight_unit'     => $this->weight_unit,
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
