<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Part extends FleetbaseResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'             => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'        => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'     => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'vendor_uuid'      => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'warranty_uuid'    => $this->when(Http::isInternalRequest(), $this->warranty_uuid),
            'photo_uuid'       => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'asset_uuid'       => $this->when(Http::isInternalRequest(), $this->asset_uuid),
            'asset_type'       => $this->when(Http::isInternalRequest(), $this->asset_type ? Utils::toEmberResourceType($this->asset_type) : null),
            'vendor'           => $this->whenLoaded('vendor', fn () => $this->vendor?->public_id),
            'warranty'         => $this->whenLoaded('warranty', fn () => $this->warranty?->public_id),
            'photo'            => $this->whenLoaded('photo', fn () => $this->photo?->public_id),
            'asset'            => $this->whenLoaded('asset', fn () => $this->asset?->public_id),
            'sku'              => $this->sku,
            'name'             => $this->name,
            'manufacturer'     => $this->manufacturer,
            'model'            => $this->model,
            'serial_number'    => $this->serial_number,
            'barcode'          => $this->barcode,
            'description'      => $this->description,
            'quantity_on_hand' => $this->quantity_on_hand,
            'unit_cost'        => $this->unit_cost,
            'msrp'             => $this->msrp,
            'currency'         => $this->currency,
            'type'             => $this->type,
            'status'           => $this->status,
            'specs'            => data_get($this, 'specs', Utils::createObject()),
            'meta'             => data_get($this, 'meta', Utils::createObject()),
            'vendor_name'      => $this->vendor_name,
            'warranty_name'    => $this->warranty_name,
            'photo_url'        => $this->photo_url,
            'total_value'      => $this->total_value,
            'is_in_stock'      => $this->is_in_stock,
            'is_low_stock'     => $this->is_low_stock,
            'asset_name'       => $this->asset_name,
            'slug'             => $this->slug,
            'updated_at'       => $this->updated_at,
            'created_at'       => $this->created_at,
        ];
    }
}
