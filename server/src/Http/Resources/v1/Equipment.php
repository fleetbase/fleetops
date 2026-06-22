<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Equipment extends FleetbaseResource
{
    public function toArray($request)
    {
        return $this->withCustomFields([
            'id'                 => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'public_id'          => $this->when(Http::isInternalRequest(), $this->public_id),
            'equipable_type'     => $this->when(Http::isInternalRequest(), $this->equipable_type ? Utils::toEmberResourceType($this->equipable_type) : null),
            'warranty'           => $this->whenLoaded('warranty', fn () => $this->warranty?->public_id),
            'photo'              => $this->whenLoaded('photo', fn () => $this->photo?->public_id),
            'equipable'          => $this->whenLoaded('equipable', fn () => $this->equipable?->public_id),
            'name'               => $this->name,
            'code'               => $this->code,
            'type'               => $this->type,
            'status'             => $this->status,
            'serial_number'      => $this->serial_number,
            'manufacturer'       => $this->manufacturer,
            'model'              => $this->model,
            'purchased_at'       => $this->purchased_at,
            'purchase_price'     => $this->purchase_price,
            'currency'           => $this->currency,
            'warranty_name'      => $this->warranty_name,
            'photo_url'          => $this->photo_url,
            'equipped_to_name'   => $this->equipped_to_name,
            'is_equipped'        => $this->is_equipped,
            'age_in_days'        => $this->age_in_days,
            'depreciated_value'  => $this->depreciated_value,
            'meta'               => data_get($this, 'meta', Utils::createObject()),
            'slug'               => $this->slug,
            'updated_at'         => $this->updated_at,
            'created_at'         => $this->created_at,
        ]);
    }
}
