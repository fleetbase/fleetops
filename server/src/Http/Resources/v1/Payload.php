<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Payload extends FleetbaseResource
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
            'id'                    => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                  => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'             => $this->when(Http::isInternalRequest(), $this->public_id),
            'current_waypoint_uuid' => $this->when(Http::isInternalRequest(), $this->current_waypoint_uuid),
            'pickup_uuid'           => $this->when(Http::isInternalRequest(), $this->pickup_uuid),
            'dropoff_uuid'          => $this->when(Http::isInternalRequest(), $this->dropoff_uuid),
            'return_uuid'           => $this->when(Http::isInternalRequest(), $this->return_uuid),
            'current_waypoint'      => $this->when(!Http::isInternalRequest() && $this->currentWaypoint, data_get($this, 'currentWaypoint.public_id')),
            'pickup'                => new Place($this->pickup),
            'dropoff'               => new Place($this->dropoff),
            'return'                => new Place($this->return),
            'waypoints'             => Waypoint::collection($this->getWaypoints()),
            'entities'              => Entity::collection($this->entities),
            'cod_amount'            => $this->cod_amount ?? null,
            'cod_currency'          => $this->cod_currency ?? null,
            'cod_payment_method'    => $this->cod_payment_method ?? null,
            'meta'                  => $this->meta ?? [],
            'updated_at'            => $this->updated_at,
            'created_at'            => $this->created_at,
        ];
    }

    private function getWaypoints(): ?\Illuminate\Support\Collection
    {
        if ($this->waypoints instanceof \Illuminate\Support\Collection) {
            return $this->waypoints->map(function ($waypoint) {
                $waypoint->payload_uuid = $this->uuid;

                return $waypoint;
            });
        }

        return [];
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'                 => $this->public_id,
            'pickup'             => new Place($this->pickup),
            'dropoff'            => new Place($this->dropoff),
            'return'             => new Place($this->return),
            'waypoints'          => static::waypoints($this->waypoints),
            'entities'           => Entity::collection($this->entities ?? []),
            'cod_amount'         => $this->cod_amount ?? null,
            'cod_currency'       => $this->cod_currency ?? null,
            'cod_payment_method' => $this->cod_payment_method ?? null,
            'meta'               => $this->meta ?? [],
            'updated_at'         => $this->updated_at,
            'created_at'         => $this->created_at,
        ];
    }
}
