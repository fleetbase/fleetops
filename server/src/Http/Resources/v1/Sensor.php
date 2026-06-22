<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Sensor extends FleetbaseResource
{
    public function toArray($request)
    {
        return $this->withCustomFields([
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'         => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'device_uuid'          => $this->when(Http::isInternalRequest(), $this->device_uuid),
            'warranty_uuid'        => $this->when(Http::isInternalRequest(), $this->warranty_uuid),
            'telematic_uuid'       => $this->when(Http::isInternalRequest(), $this->telematic_uuid),
            'photo_uuid'           => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'sensorable_uuid'      => $this->when(Http::isInternalRequest(), $this->sensorable_uuid),
            'sensorable_type'      => $this->when(Http::isInternalRequest(), $this->sensorable_type ? Utils::toEmberResourceType($this->sensorable_type) : null),
            'device'               => $this->whenLoaded('device', fn () => $this->device?->public_id),
            'warranty'             => $this->whenLoaded('warranty', fn () => $this->warranty?->public_id),
            'telematic'            => $this->whenLoaded('telematic', fn () => $this->telematic?->public_id),
            'photo'                => $this->whenLoaded('photo', fn () => $this->photo?->public_id),
            'sensorable'           => $this->whenLoaded('sensorable', fn () => $this->sensorable?->public_id),
            'name'                 => $this->name,
            'type'                 => $this->type,
            'internal_id'          => $this->internal_id,
            'imei'                 => $this->imei,
            'imsi'                 => $this->imsi,
            'firmware_version'     => $this->firmware_version,
            'serial_number'        => $this->serial_number,
            'last_position'        => $this->last_position,
            'unit'                 => $this->unit,
            'min_threshold'        => $this->min_threshold,
            'max_threshold'        => $this->max_threshold,
            'threshold_inclusive'  => $this->threshold_inclusive,
            'last_reading_at'      => $this->last_reading_at,
            'last_value'           => $this->last_value,
            'calibration'          => data_get($this, 'calibration', Utils::createObject()),
            'report_frequency_sec' => $this->report_frequency_sec,
            'status'               => $this->status,
            'meta'                 => data_get($this, 'meta', Utils::createObject()),
            'is_active'            => $this->is_active,
            'threshold_status'     => $this->threshold_status,
            'photo_url'            => $this->photo_url,
            'device_name'          => $this->device_name,
            'warranty_name'        => $this->warranty_name,
            'attached_to_name'     => $this->attached_to_name,
            'slug'                 => $this->slug,
            'updated_at'           => $this->updated_at,
            'created_at'           => $this->created_at,
        ]);
    }
}
