<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Device extends FleetbaseResource
{
    public function toArray($request)
    {
        return $this->withCustomFields([
            'id'                    => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                  => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'             => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'          => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'telematic_uuid'        => $this->when(Http::isInternalRequest(), $this->telematic_uuid),
            'attachable_uuid'       => $this->when(Http::isInternalRequest(), $this->attachable_uuid),
            'attachable_type'       => $this->when(Http::isInternalRequest(), $this->attachable_type ? Utils::toEmberResourceType($this->attachable_type) : null),
            'warranty_uuid'         => $this->when(Http::isInternalRequest(), $this->warranty_uuid),
            'photo_uuid'            => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'telematic'             => $this->whenLoaded('telematic', fn () => $this->telematic?->public_id),
            'attachable'            => $this->whenLoaded('attachable', fn () => $this->attachable?->public_id),
            'warranty'              => $this->whenLoaded('warranty', fn () => $this->warranty?->public_id),
            'photo'                 => $this->whenLoaded('photo', fn () => $this->photo?->public_id),
            'type'                  => $this->type,
            'device_id'             => $this->device_id,
            'internal_id'           => $this->internal_id,
            'imei'                  => $this->imei,
            'imsi'                  => $this->imsi,
            'firmware_version'      => $this->firmware_version,
            'provider'              => $this->provider,
            'name'                  => $this->name,
            'model'                 => $this->model,
            'location'              => $this->location,
            'manufacturer'          => $this->manufacturer,
            'serial_number'         => $this->serial_number,
            'last_position'         => $this->last_position,
            'installation_date'     => $this->installation_date,
            'last_maintenance_date' => $this->last_maintenance_date,
            'meta'                  => data_get($this, 'meta', Utils::createObject()),
            'data'                  => data_get($this, 'data', Utils::createObject()),
            'options'               => data_get($this, 'options', Utils::createObject()),
            'online'                => $this->online,
            'status'                => $this->status,
            'data_frequency'        => $this->data_frequency,
            'notes'                 => $this->notes,
            'last_online_at'        => $this->last_online_at,
            'warranty_name'         => $this->warranty_name,
            'telematic_name'        => $this->telematic_name,
            'is_online'             => $this->is_online,
            'attached_to_name'      => $this->attached_to_name,
            'connection_status'     => $this->connection_status,
            'photo_url'             => $this->photo_url,
            'sensors_count'         => $this->when(isset($this->sensors_count), $this->sensors_count),
            'slug'                  => $this->slug,
            'updated_at'            => $this->updated_at,
            'created_at'            => $this->created_at,
        ]);
    }
}
