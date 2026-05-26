<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class ServiceArea extends FleetbaseResource
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
        return $this->withCustomFields([
            'id'                      => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                    => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'               => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'                    => $this->name,
            'type'                    => $this->type,
            'center'                  => $this->location,
            'border'                  => $this->border,
            'zones'                   => $this->whenLoaded('zones', fn () => Zone::collection($this->zones)),
            'color'                   => $this->color,
            'stroke_color'            => $this->stroke_color,
            'trigger_on_entry'        => $this->trigger_on_entry,
            'trigger_on_exit'         => $this->trigger_on_exit,
            'dwell_threshold_minutes' => $this->dwell_threshold_minutes,
            'speed_limit_kmh'         => $this->speed_limit_kmh,
            'country'                 => $this->country,
            'status'                  => $this->status,
            'updated_at'              => $this->updated_at,
            'created_at'              => $this->created_at,
        ]);
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'                      => $this->public_id,
            'name'                    => $this->name,
            'type'                    => $this->type,
            'center'                  => $this->location,
            'border'                  => $this->border,
            'color'                   => $this->color,
            'stroke_color'            => $this->stroke_color,
            'trigger_on_entry'        => $this->trigger_on_entry,
            'trigger_on_exit'         => $this->trigger_on_exit,
            'dwell_threshold_minutes' => $this->dwell_threshold_minutes,
            'speed_limit_kmh'         => $this->speed_limit_kmh,
            'country'                 => $this->country,
            'status'                  => $this->status,
            'updated_at'              => $this->updated_at,
            'created_at'              => $this->created_at,
        ];
    }
}
