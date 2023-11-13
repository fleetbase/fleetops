<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;
use Grimzy\LaravelMysqlSpatial\Types\Point;

class Place extends FleetbaseResource
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
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'name'                 => $this->name,
            'location'             => $this->location ?? new Point(0, 0),
            'address'              => $this->address,
            'address_html'         => $this->when(Http::isInternalRequest(), $this->address_html),
            'street1'              => $this->street1 ?? null,
            'street2'              => $this->street2 ?? null,
            'city'                 => $this->city ?? null,
            'province'             => $this->province ?? null,
            'postal_code'          => $this->postal_code ?? null,
            'neighborhood'         => $this->neighborhood ?? null,
            'district'             => $this->district ?? null,
            'building'             => $this->building ?? null,
            'security_access_code' => $this->security_access_code ?? null,
            'country'              => $this->country ?? null,
            'country_name'         => $this->when(Http::isInternalRequest(), $this->country_name),
            'phone'                => $this->phone ?? null,
            'owner'                => $this->when(!Http::isInternalRequest(), Resolve::resourceForMorph($this->owner_type, $this->owner_uuid)),
            'tracking_number'      => $this->whenLoaded('trackingNumber', $this->trackingNumber),
            'type'                 => $this->type ?? null,
            'meta'                 => $this->meta ?? [],
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
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
            'id'                   => $this->public_id,
            'internal_id'          => $this->internal_id,
            'name'                 => $this->name,
            'latitude'             => $this->latitude ?? null,
            'longitude'            => $this->longitude ?? null,
            'street1'              => $this->street1 ?? null,
            'street2'              => $this->street2 ?? null,
            'city'                 => $this->city ?? null,
            'province'             => $this->province ?? null,
            'postal_code'          => $this->postal_code ?? null,
            'neighborhood'         => $this->neighborhood ?? null,
            'district'             => $this->district ?? null,
            'building'             => $this->building ?? null,
            'security_access_code' => $this->security_access_code ?? null,
            'country'              => $this->country ?? null,
            'phone'                => $this->phone ?? null,
            'owner'                => Resolve::resourceForMorph($this->owner_type, $this->owner_uuid),
            'type'                 => $this->type ?? null,
            'meta'                 => $this->meta ?? [],
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ];
    }
}
