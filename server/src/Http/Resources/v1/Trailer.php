<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Concerns\ResolvesPublicRelationFields;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\Support\Http;
use Fleetbase\Support\Resolve;

/**
 * First-class Trailer representation.
 *
 * Internal (console) responses expose the record with its UUID identity and fully
 * embedded relations so Ember Data can hydrate the store. Public API responses use
 * the `trailer_` public identifier, additive `*_id` relation identifiers, and only
 * expand relation objects that were requested through `with`.
 */
class Trailer extends FleetbaseResource
{
    use ResolvesPublicRelationFields;

    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        $internal = Http::isInternalRequest();
        $with     = $this->requestedRelations($request);

        return $this->withCustomFields([
            // Identity
            'id'                   => $this->when($internal, $this->id, $this->public_id),
            'uuid'                 => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'            => $this->when($internal, $this->public_id),
            'company_uuid'         => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'category_uuid'        => $this->when(Http::isInternalRequest(), $this->category_uuid),
            'vendor_uuid'          => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'warranty_uuid'        => $this->when(Http::isInternalRequest(), $this->warranty_uuid),
            'photo_uuid'           => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'asset_class'          => 'trailer',
            'name'                 => $this->name,
            'display_name'         => $this->display_name,
            'code'                 => $this->code,
            'description'          => $this->description,
            'slug'                 => $this->when($internal, $this->slug),
            // Classification & lifecycle
            'type'                 => $this->type,
            'body_type'            => $this->body_type,
            'usage_type'           => $this->usage_type,
            'status'               => $this->status,
            'attachment_state'     => $this->attachment_state,
            'connectivity_status'  => $this->connectivity_status,
            'online'               => $this->online,
            // Registration
            'vin'                  => $this->vin,
            'plate_number'         => $this->plate_number,
            'serial_number'        => $this->serial_number,
            'make'                 => $this->make,
            'model'                => $this->model,
            'year'                 => $this->year,
            'color'                => $this->color,
            // Media
            'photo_url'            => $this->photo_url,
            // Relationships. Identifiers are additive and always present so a write
            // can be read back; relation objects follow the public `with` contract.
            'category_id'          => $this->publicIdForRelation('category', 'category_uuid'),
            'vendor_id'            => $this->publicIdForRelation('vendor', 'vendor_uuid'),
            'warranty_id'          => $this->publicIdForRelation('warranty', 'warranty_uuid'),
            'photo_id'             => $this->publicIdForRelation('photo', 'photo_uuid'),
            'category_name'        => $this->when($internal, $this->category_name),
            'vendor_name'          => $this->when($internal, $this->vendor_name),
            'warranty_name'        => $this->when($internal, $this->warranty_name),
            'category'             => $this->publicRelationObject('category', $with, fn () => Resolve::httpResourceForModel($this->category)),
            'vendor'               => $this->publicRelationObject('vendor', $with, fn () => new Vendor($this->vendor)),
            'warranty'             => $this->publicRelationObject('warranty', $with, fn () => Resolve::httpResourceForModel($this->warranty)),
            'photo'                => $this->publicRelationObject('photo', $with, fn () => Resolve::httpResourceForModel($this->photo)),
            // Towing connection
            'vehicle_id'           => $this->whenLoaded('currentConnection', fn () => $this->currentConnection?->vehicle?->public_id),
            'current_vehicle_name' => $this->whenLoaded('currentConnection', fn () => $this->currentConnection?->vehicle?->display_name),
            'attached_at'          => $this->whenLoaded('currentConnection', fn () => $this->currentConnection?->connected_at),
            'current_vehicle'      => $this->whenLoaded('currentConnection', fn () => $this->currentVehicleRepresentation($internal)),
            'current_connection'   => $this->whenLoaded('currentConnection', fn () => $this->currentConnection ? new AssetConnection($this->currentConnection) : null),
            // Ember Data embeds these collections, so the console always receives an array;
            // the public API only includes them when they were loaded.
            'connections'          => $this->embeddedCollection('connections', AssetConnection::class, $internal),
            'devices'              => $this->embeddedCollection('devices', Device::class, $internal),
            // Ember Data embeds the `equipments` relationship; the public contract reads `equipment`.
            'equipment'            => $this->when(!$internal, fn () => $this->whenLoaded('equipments', fn () => Equipment::collection($this->equipments))),
            'equipments'           => $this->when($internal, fn () => $this->embeddedCollection('equipments', Equipment::class, true)),
            'devices_count'        => $this->whenCounted('devices'),
            'equipment_count'      => $this->whenCounted('equipments'),
            // Dimensions & capacity
            'measurement_system'   => $this->measurement_system,
            'length'               => $this->length,
            'width'                => $this->width,
            'height'               => $this->height,
            'tare_weight'          => $this->tare_weight,
            'gvwr'                 => $this->gvwr,
            'payload_capacity'     => $this->payload_capacity,
            'cargo_volume'         => $this->cargo_volume,
            'capacity'             => data_get($this, 'capacity', Utils::createObject()),
            // Running gear
            'axle_count'           => $this->axle_count,
            'tire_count'           => $this->tire_count,
            'door_count'           => $this->door_count,
            'coupling_type'        => $this->coupling_type,
            'brake_type'           => $this->brake_type,
            'abs_equipped'         => $this->abs_equipped,
            'ebs_equipped'         => $this->ebs_equipped,
            // Refrigeration
            'refrigerated'         => $this->refrigerated,
            'temperature_min'      => $this->temperature_min,
            'temperature_max'      => $this->temperature_max,
            'reefer_engine_hours'  => $this->reefer_engine_hours,
            // Usage
            'odometer'             => $this->odometer,
            'odometer_unit'        => $this->odometer_unit,
            'engine_hours'         => $this->engine_hours,
            // Ownership & finance
            'ownership_type'       => $this->ownership_type,
            'financing_status'     => $this->financing_status,
            'currency'             => $this->currency,
            'acquisition_cost'     => $this->acquisition_cost,
            'current_value'        => $this->current_value,
            'insurance_value'      => $this->insurance_value,
            'depreciation_rate'    => $this->depreciation_rate,
            'purchased_at'         => $this->purchased_at,
            'lease_expires_at'     => $this->lease_expires_at,
            // Location & telematics
            'location'             => Utils::castPoint($this->location),
            'speed'                => $this->speed,
            'heading'              => $this->heading,
            'altitude'             => $this->altitude,
            'last_online_at'       => $this->last_online_at,
            'telematics'           => $this->telematicsRepresentation($internal),
            'positions'            => $this->whenLoaded('positions', fn () => Position::collection($this->positions)),
            // Blobs, notes & timestamps
            'specs'                => data_get($this, 'specs', Utils::createObject()),
            'attributes'           => $this->resource->getAttribute('attributes') ?? Utils::createObject(),
            'notes'                => $this->notes,
            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,
        ]);
    }

    /**
     * A loaded relation serializes through its resource; an unloaded one is omitted for the
     * public API and an empty array for the console, which embeds the relationship.
     */
    protected function embeddedCollection(string $relation, string $resourceClass, bool $internal): mixed
    {
        if ($this->resource->relationLoaded($relation)) {
            return $resourceClass::collection($this->{$relation});
        }

        return $internal ? [] : new \Illuminate\Http\Resources\MissingValue();
    }

    /**
     * The console receives the full Vehicle record so Ember Data can hydrate it; the
     * public API receives a compact public identity.
     */
    protected function currentVehicleRepresentation(bool $internal): mixed
    {
        $vehicle = $this->currentConnection?->vehicle;

        if (!$vehicle) {
            return null;
        }

        if ($internal) {
            return Resolve::httpResourceForModel($vehicle);
        }

        return [
            'id'           => $vehicle->public_id,
            'name'         => $vehicle->display_name,
            'plate_number' => $vehicle->plate_number,
        ];
    }

    /**
     * Telemetry provenance references devices and events by UUID for the console;
     * the public API must never expose internal identifiers.
     */
    protected function telematicsRepresentation(bool $internal): mixed
    {
        $telematics = $this->telematics;

        if ($internal || !is_array($telematics)) {
            return $telematics;
        }

        return array_filter($telematics, fn ($key) => !preg_match('/(^uuid$|_uuid$)/', (string) $key), ARRAY_FILTER_USE_KEY);
    }
}
