<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Vehicle extends FleetbaseResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return array_merge(
            $this->getInternalIds(),
            [
                'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
                'uuid' => $this->when(Http::isInternalRequest(), $this->uuid),
                'public_id' => $this->when(Http::isInternalRequest(), $this->public_id),
                'internal_id' => $this->internal_id,
                'name' => $this->display_name,
                'display_name' => $this->when(Http::isInternalRequest(), $this->display_name),
                'vin' => $this->vin ?? null,
                'driver' => $this->whenLoaded('driver', new Driver($this->driver)),
                'photo' => $this->photoUrl,
                'make' => $this->make,
                'model' => $this->model,
                'year' => $this->year,
                'trim' => $this->trim,
                'type' => $this->type,
                'plate_number' => $this->plate_number,
                'vin_data' => $this->vin_data,
                'status' => $this->status,
                'online' => $this->online,
                'meta' => $this->meta ?? [],
                'updated_at' => $this->updated_at,
                'created_at' => $this->created_at,
            ]
        );
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id' => $this->public_id,
            'internal_id' => $this->internal_id,
            'name' => $this->name,
            'vin' => $this->vin ?? null,
            'driver' => $this->whenLoaded('driver', new Driver($this->driver)),
            'photo' => $this->photoUrl,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'trim' => $this->trim,
            'type' => $this->type,
            'plate_number' => $this->plate_number,
            'vin_data' => $this->vin_data,
            'status' => $this->status,
            'online' => $this->online,
            'meta' => $this->meta ?? [],
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ];
    }
}
