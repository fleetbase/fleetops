<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

class Entity extends FleetbaseResource
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
            'id'              => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'            => $this->when(Http::isInternalRequest(), $this->uuid),
            'photo_uuid'      => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'public_id'       => $this->when(Http::isInternalRequest(), $this->public_id),
            'customer_uuid'   => $this->when(Http::isInternalRequest(), $this->customer_uuid),
            'customer_type'   => $this->when(Http::isInternalRequest(), $this->customer_type),
            'internal_id'     => $this->internal_id,
            'name'            => $this->name,
            'type'            => $this->type ?? null,
            'destination'     => $this->when(Http::isPublicRequest(), data_get($this, 'destination.public_id'), null),
            'customer'        => $this->setCustomerType(Resolve::resourceForMorph($this->customer_type, $this->customer_uuid)),
            'tracking_number' => new TrackingNumber($this->trackingNumber),
            'description'     => $this->description ?? null,
            'photo_url'       => $this->photo_url ?? null,
            'length'          => $this->length ?? null,
            'width'           => $this->width ?? null,
            'height'          => $this->height ?? null,
            'dimensions_unit' => $this->dimensions_unit ?? null,
            'weight'          => $this->weight ?? null,
            'weight_unit'     => $this->weight_unit ?? null,
            'declared_value'  => $this->declared_value ?? null,
            'price'           => $this->price ?? null,
            'sale_price'      => $this->sale_price ?? null,
            'sku'             => $this->sku ?? null,
            'currency'        => $this->currency ?? null,
            'meta'            => $this->meta ?? [],
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }

    /**
     * Set the customer type for the given data array.
     *
     * @param array $resolved the input data array
     *
     * @return array the modified data array with the customer type set
     */
    public function setCustomerType($resolved)
    {
        if (empty($resolved)) {
            return $resolved;
        }

        data_set($resolved, 'type', 'customer');
        data_set($resolved, 'customer_type', Utils::toEmberResourceType($this->customer_type));

        return $resolved;
    }

    /**
     * Transform the resource into an webhook payload.
     *
     * @return array
     */
    public function toWebhookPayload()
    {
        return [
            'id'              => $this->public_id,
            'internal_id'     => $this->internal_id,
            'name'            => $this->name,
            'type'            => $this->type ?? null,
            'destination'     => $this->destination ? $this->destination->public_id : null,
            'customer'        => Resolve::resourceForMorph($this->customer_type, $this->customer_uuid),
            'tracking_number' => new TrackingNumber($this->trackingNumber),
            'description'     => $this->description ?? null,
            'photo_url'       => $this->photo_url ?? null,
            'length'          => $this->length ?? null,
            'width'           => $this->width ?? null,
            'height'          => $this->height ?? null,
            'dimensions_unit' => $this->dimensions_unit ?? null,
            'weight'          => $this->weight ?? null,
            'weight_unit'     => $this->weight_unit ?? null,
            'declared_value'  => $this->declared_value ?? null,
            'price'           => $this->price ?? null,
            'sale_price'      => $this->sale_price ?? null,
            'sku'             => $this->sku ?? null,
            'currency'        => $this->currency ?? null,
            'meta'            => $this->meta ?? [],
            'updated_at'      => $this->updated_at,
            'created_at'      => $this->created_at,
        ];
    }
}
