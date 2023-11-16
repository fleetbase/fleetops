<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Models\Waypoint;
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
            'current_waypoint'      => $this->when(!Http::isInternalRequest() && $this->currentWaypoint, data_get($this, 'currentWaypoint.public_id'), null),
            'pickup'                => new Place($this->pickup),
            'dropoff'               => new Place($this->dropoff),
            'return'                => new Place($this->return),
            'waypoints'             => $this->waypoints($this->waypoints),
            'entities'              => Entity::collection($this->entities ?? []),
            'cod_amount'            => $this->cod_amount ?? null,
            'cod_currency'          => $this->cod_currency ?? null,
            'cod_payment_method'    => $this->cod_payment_method ?? null,
            'meta'                  => $this->meta ?? [],
            'updated_at'            => $this->updated_at,
            'created_at'            => $this->created_at,
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

    /**
     * Returns the correct pickup resource if applicable.
     *
     * @param \Illuminate\Support\Collection $waypoints
     *
     * @return Illuminate\Http\Resources\Json\JsonResource|null
     */
    public function waypoints($waypoints)
    {
        if (empty($waypoints)) {
            return [];
        }

        $waypoints = $waypoints->map(
            function ($place) {
                $waypoint               = Waypoint::where(['payload_uuid' => $this->uuid, 'place_uuid' => $place->uuid])->without(['place'])->with(['trackingNumber'])->first();
                $place->tracking_number = new TrackingNumber($waypoint->trackingNumber);
                $place->order           = $waypoint->order;

                return $place;
            }
        )->sortBy('order');

        return Place::collection($waypoints ?? []);
    }
}
