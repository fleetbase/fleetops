<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Contact extends FleetbaseResource
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
            'id'          => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'        => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'   => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id' => $this->internal_id,
            'name'        => $this->name,
            'title'       => $this->title ?? null,
            'email'       => $this->email ?? null,
            'phone'       => $this->phone ?? null,
            'photo_url'   => $this->photo_url ?? null,
            'type'        => $this->type ?? null,
            'meta'        => $this->meta ?? [],
            'slug'        => $this->slug ?? null,
            'updated_at'  => $this->updated_at,
            'created_at'  => $this->created_at,
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
            'id'          => $this->public_id,
            'internal_id' => $this->internal_id,
            'name'        => $this->name,
            'title'       => $this->title ?? null,
            'email'       => $this->email ?? null,
            'phone'       => $this->phone ?? null,
            'photo_url'   => $this->photo_url ?? null,
            'type'        => $this->type ?? null,
            'meta'        => $this->meta ?? [],
            'slug'        => $this->slug ?? null,
            'updated_at'  => $this->updated_at,
            'created_at'  => $this->created_at,
        ];
    }
}
