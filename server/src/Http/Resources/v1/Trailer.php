<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;

class Trailer extends FleetbaseResource
{
    public function toArray($request): array
    {
        $internal = Http::isInternalRequest();

        return $this->withCustomFields([
            'id'                   => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when(Http::isInternalRequest(), $this->public_id),
            'company_uuid'         => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'asset_class'          => $this->when($internal, 'trailer'), 'name' => $this->name, 'display_name' => $this->display_name,
            'code'                 => $this->code, 'description' => $this->description, 'type' => $this->type, 'body_type' => $this->body_type,
            'status'               => $this->status, 'attachment_state' => $this->attachment_state, 'connectivity_status' => $this->connectivity_status,
            'online'               => $this->online, 'vin' => $this->vin, 'plate_number' => $this->plate_number, 'serial_number' => $this->serial_number,
            'make'                 => $this->make, 'model' => $this->model, 'year' => $this->year, 'color' => $this->color, 'photo_url' => $this->photo_url,
            'vendor'               => $this->whenLoaded('vendor', fn () => $internal ? $this->vendor : $this->vendor?->public_id),
            'warranty'             => $this->whenLoaded('warranty', fn () => $internal ? $this->warranty : $this->warranty?->public_id),
            'photo'                => $this->whenLoaded('photo', fn () => $internal ? $this->photo : $this->photo?->public_id),
            'current_vehicle'      => $this->whenLoaded('currentConnection', fn () => $this->currentConnection?->vehicle ? ['id' => $this->currentConnection->vehicle->public_id, 'name' => $this->currentConnection->vehicle->display_name, 'plate_number' => $this->currentConnection->vehicle->plate_number] : null),
            'current_vehicle_name' => $this->whenLoaded('currentConnection', fn () => $this->currentConnection?->vehicle?->display_name),
            'current_connection'   => $this->whenLoaded('currentConnection', fn () => $this->currentConnection ? new AssetConnection($this->currentConnection) : null),
            'connections'          => $this->whenLoaded('connections', fn () => AssetConnection::collection($this->connections)),
            'devices'              => $this->whenLoaded('devices', fn () => Device::collection($this->devices)),
            'equipment'            => $this->whenLoaded('equipments', fn () => Equipment::collection($this->equipments)),
            'equipments'           => $this->whenLoaded('equipments', fn () => Equipment::collection($this->equipments)),
            'positions'            => $this->whenLoaded('positions', fn () => Position::collection($this->positions)),
            'devices_count'        => $this->whenCounted('devices'), 'equipment_count' => $this->whenCounted('equipments'),
            'length'               => $this->length, 'width' => $this->width, 'height' => $this->height, 'tare_weight' => $this->tare_weight,
            'gvwr'                 => $this->gvwr, 'payload_capacity' => $this->payload_capacity, 'cargo_volume' => $this->cargo_volume,
            'axle_count'           => $this->axle_count, 'tire_count' => $this->tire_count, 'door_count' => $this->door_count,
            'coupling_type'        => $this->coupling_type, 'brake_type' => $this->brake_type, 'abs_equipped' => $this->abs_equipped,
            'ebs_equipped'         => $this->ebs_equipped, 'refrigerated' => $this->refrigerated, 'temperature_min' => $this->temperature_min,
            'temperature_max'      => $this->temperature_max, 'reefer_engine_hours' => $this->reefer_engine_hours,
            'measurement_system'   => $this->measurement_system, 'odometer' => $this->odometer, 'odometer_unit' => $this->odometer_unit,
            'ownership_type'       => $this->ownership_type, 'purchased_at' => $this->purchased_at, 'lease_expires_at' => $this->lease_expires_at,
            'financing_status'     => $this->financing_status, 'currency' => $this->currency, 'acquisition_cost' => $this->acquisition_cost,
            'current_value'        => $this->current_value, 'insurance_value' => $this->insurance_value, 'depreciation_rate' => $this->depreciation_rate,
            'location'             => Utils::castPoint($this->location), 'speed' => $this->speed, 'heading' => $this->heading, 'altitude' => $this->altitude,
            'last_online_at'       => $this->last_online_at, 'telematics' => $this->telematics, 'capacity' => $this->capacity ?? (object) [],
            'specs'                => $this->specs ?? (object) [], 'attributes' => $this->resource->getAttribute('attributes') ?? (object) [], 'notes' => $this->notes,
            'created_at'           => $this->created_at, 'updated_at' => $this->updated_at,
        ]);
    }
}
