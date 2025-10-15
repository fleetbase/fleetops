<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Http\Resources\User;
use Fleetbase\Support\Http;
use Illuminate\Support\Str;

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
        $this->loadMissing(['place', 'places']);

        return $this->withCustomFields([
            'id'                            => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'customer_id'                   => $this->when($this->type === 'customer' && Http::isPublicRequest(), Str::replace('contact', 'customer', $this->public_id)),
            'uuid'                          => $this->when(Http::isInternalRequest(), $this->uuid),
            'company_uuid'                  => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'user_uuid'                     => $this->when(Http::isInternalRequest(), $this->user_uuid),
            'place_uuid'                    => $this->when(Http::isInternalRequest(), $this->place_uuid),
            'photo_uuid'                    => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'public_id'                     => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id'                   => $this->internal_id,
            'name'                          => $this->name,
            'title'                         => $this->title ?? null,
            'email'                         => $this->email ?? null,
            'phone'                         => $this->phone ?? null,
            'photo_url'                     => $this->photo_url ?? null,
            'place'                         => $this->whenLoaded('place', fn () => (new Place($this->place))->without('owner')),
            'places'                        => $this->whenLoaded('places', fn () => Place::collection($this->places)->without('owner')),
            'user'                          => $this->when(Http::isInternalRequest(), fn () => new User($this->user), fn () => $this->user ? $this->user->public_id : null),
            'address'                       => $this->when(Http::isInternalRequest(), data_get($this, 'place.address')),
            'address_street'                => $this->when(Http::isInternalRequest(), data_get($this, 'place.street1')),
            'type'                          => $this->type ?? null,
            'customer_type'                 => $this->when(isset($this->customer_type), $this->customer_type),
            'facilitator_type'              => $this->when(isset($this->facilitator_type), $this->facilitator_type),
            'meta'                          => data_get($this, 'meta', Utils::createObject()),
            'slug'                          => $this->slug ?? null,
            'updated_at'                    => $this->updated_at,
            'created_at'                    => $this->created_at,
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
            'id'          => $this->public_id,
            'internal_id' => $this->internal_id,
            'name'        => $this->name,
            'title'       => $this->title ?? null,
            'email'       => $this->email ?? null,
            'phone'       => $this->phone ?? null,
            'photo_url'   => $this->photo_url ?? null,
            'type'        => $this->type ?? null,
            'meta'        => data_get($this, 'meta', Utils::createObject()),
            'slug'        => $this->slug ?? null,
            'updated_at'  => $this->updated_at,
            'created_at'  => $this->created_at,
        ];
    }
}
