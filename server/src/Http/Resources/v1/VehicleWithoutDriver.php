<?php

namespace Fleetbase\FleetOps\Http\Resources\v1;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Resources\FleetbaseResource;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Support\Http;

class VehicleWithoutDriver extends FleetbaseResource
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
        return $this->withCustomFields([
            // Identity
            'id'                     => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'uuid'                   => $this->when(Http::isInternalRequest(), $this->uuid),
            'public_id'              => $this->when(Http::isInternalRequest(), $this->public_id),
            'internal_id'            => $this->internal_id,
            'company_uuid'           => $this->when(Http::isInternalRequest(), $this->company_uuid),
            'vendor_uuid'            => $this->when(Http::isInternalRequest(), $this->vendor_uuid),
            'category_uuid'          => $this->when(Http::isInternalRequest(), $this->category_uuid),
            'warranty_uuid'          => $this->when(Http::isInternalRequest(), $this->warranty_uuid),
            'telematic_uuid'         => $this->when(Http::isInternalRequest(), $this->telematic_uuid),
            // Media
            'photo_uuid'             => $this->when(Http::isInternalRequest(), $this->photo_uuid),
            'photo_url'              => $this->photo_url,
            'avatar_url'             => $this->avatar_url,
            'avatar_value'           => $this->when(Http::isInternalRequest(), $this->getOriginal('avatar_url')),
            // Basic info
            'name'                   => $this->name,
            'display_name'           => $this->when(Http::isInternalRequest(), $this->display_name),
            'description'            => $this->description,
            'driver_name'            => $this->when(Http::isInternalRequest(), $this->driver_name),
            'vendor_name'            => $this->when(Http::isInternalRequest(), $this->vendor_name),
            // Relationships
            'devices'                => $this->whenLoaded('devices', fn () => $this->devices),
            // Vehicle identification
            'make'                   => $this->make,
            'model'                  => $this->model,
            'model_type'             => $this->model_type,
            'year'                   => $this->year,
            'trim'                   => $this->trim,
            'type'                   => $this->type,
            'class'                  => $this->class,
            'color'                  => $this->color,
            'serial_number'          => $this->serial_number,
            'plate_number'           => $this->plate_number,
            'call_sign'              => $this->call_sign,
            // VIN & specs blobs
            'vin'                    => $this->vin ?? null,
            'vin_data'               => data_get($this, 'vin_data', Utils::createObject()),
            'specs'                  => data_get($this, 'specs', Utils::createObject()),
            'details'                => data_get($this, 'details', Utils::createObject()),
            // Status / assignment
            'status'                 => $this->status,
            'online'                 => (bool) $this->online,
            'slug'                   => $this->slug,
            'financing_status'       => $this->financing_status,
            // Measurement & usage
            'measurement_system'     => $this->measurement_system,
            'odometer'               => $this->odometer,
            'odometer_unit'          => $this->odometer_unit,
            'odometer_at_purchase'   => $this->odometer_at_purchase,
            'fuel_type'              => $this->fuel_type,
            'fuel_volume_unit'       => $this->fuel_volume_unit,
            // Body & usage
            'body_type'              => $this->body_type,
            'body_sub_type'          => $this->body_sub_type,
            'usage_type'             => $this->usage_type,
            'ownership_type'         => $this->ownership_type,
            'transmission'           => $this->transmission,
            // Engine & powertrain
            'engine_number'          => $this->engine_number,
            'engine_make'            => $this->engine_make,
            'engine_model'           => $this->engine_model,
            'engine_family'          => $this->engine_family,
            'engine_configuration'   => $this->engine_configuration,
            'engine_size'            => $this->engine_size,
            'engine_displacement'    => $this->engine_displacement,
            'cylinder_arrangement'   => $this->cylinder_arrangement,
            'number_of_cylinders'    => $this->number_of_cylinders,
            'horsepower'             => $this->horsepower,
            'horsepower_rpm'         => $this->horsepower_rpm,
            'torque'                 => $this->torque,
            'torque_rpm'             => $this->torque_rpm,
            // Capacity & dimensions
            'fuel_capacity'          => $this->fuel_capacity,
            'payload_capacity'       => $this->payload_capacity,
            'towing_capacity'        => $this->towing_capacity,
            'seating_capacity'       => $this->seating_capacity,
            'weight'                 => $this->weight,
            'length'                 => $this->length,
            'width'                  => $this->width,
            'height'                 => $this->height,
            'cargo_volume'           => $this->cargo_volume,
            'passenger_volume'       => $this->passenger_volume,
            'interior_volume'        => $this->interior_volume,
            'ground_clearance'       => $this->ground_clearance,
            'bed_length'             => $this->bed_length,
            // Regulatory / compliance
            'emission_standard'      => $this->emission_standard,
            'dpf_equipped'           => $this->dpf_equipped,
            'scr_equipped'           => $this->scr_equipped,
            'gvwr'                   => $this->gvwr,
            'gcwr'                   => $this->gcwr,
            // Lifecycle / service life
            'estimated_service_life_distance'       => $this->estimated_service_life_distance,
            'estimated_service_life_distance_unit'  => $this->estimated_service_life_distance_unit,
            'estimated_service_life_months'         => $this->estimated_service_life_months,
            // Financing
            'currency'               => $this->currency,
            'acquisition_cost'       => $this->acquisition_cost,
            'current_value'          => $this->current_value,
            'insurance_value'        => $this->insurance_value,
            'depreciation_rate'      => $this->depreciation_rate,
            'loan_amount'            => $this->loan_amount,
            'loan_number_of_payments'=> $this->loan_number_of_payments,
            'loan_first_payment'     => $this->loan_first_payment,
            // Dates
            'purchased_at'           => $this->purchased_at,
            'lease_expires_at'       => $this->lease_expires_at,
            'deleted_at'             => $this->deleted_at,
            'updated_at'             => $this->updated_at,
            'created_at'             => $this->created_at,
            // Location & telematics
            'location'               => data_get($this, 'location', new Point(0, 0)),
            'heading'                => (int) data_get($this, 'heading', 0),
            'altitude'               => (int) data_get($this, 'altitude', 0),
            'speed'                  => (int) data_get($this, 'speed', 0),
            'telematics'             => data_get($this, 'telematics'),
            // Notes & meta
            'notes'                  => $this->notes,
            'meta'                   => data_get($this, 'meta', Utils::createObject()),
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
            // Identity
            'id'                         => $this->public_id,
            'internal_id'                => $this->internal_id,
            // Basic info
            'name'                       => $this->name,
            'display_name'               => $this->display_name,
            'description'                => $this->description,
            // Vehicle identification
            'vin'                        => $this->vin,
            'plate_number'               => $this->plate_number,
            'serial_number'              => $this->serial_number,
            'make'                       => $this->make,
            'model'                      => $this->model,
            'model_type'                 => $this->model_type,
            'year'                       => $this->year,
            'trim'                       => $this->trim,
            'type'                       => $this->type,
            'class'                      => $this->class,
            'color'                      => $this->color,
            'call_sign'                  => $this->call_sign,
            // Media
            'photo_url'                  => $this->photo_url,
            'avatar_url'                 => $this->avatar_url,
            // Status / assignment
            'status'                     => $this->status,
            'online'                     => (bool) $this->online,
            'slug'                       => $this->slug,
            'financing_status'           => $this->financing_status,
            // Measurement & usage
            'measurement_system'         => $this->measurement_system,
            'odometer'                   => $this->odometer,
            'odometer_unit'              => $this->odometer_unit,
            'odometer_at_purchase'       => $this->odometer_at_purchase,
            'fuel_type'                  => $this->fuel_type,
            'fuel_volume_unit'           => $this->fuel_volume_unit,
            // Body & usage
            'body_type'                  => $this->body_type,
            'body_sub_type'              => $this->body_sub_type,
            'usage_type'                 => $this->usage_type,
            'ownership_type'             => $this->ownership_type,
            'transmission'               => $this->transmission,
            // Engine & powertrain
            'engine_number'              => $this->engine_number,
            'engine_make'                => $this->engine_make,
            'engine_model'               => $this->engine_model,
            'engine_family'              => $this->engine_family,
            'engine_configuration'       => $this->engine_configuration,
            'engine_size'                => $this->engine_size,
            'engine_displacement'        => $this->engine_displacement,
            'cylinder_arrangement'       => $this->cylinder_arrangement,
            'number_of_cylinders'        => $this->number_of_cylinders,
            'horsepower'                 => $this->horsepower,
            'horsepower_rpm'             => $this->horsepower_rpm,
            'torque'                     => $this->torque,
            'torque_rpm'                 => $this->torque_rpm,
            // Capacity & dimensions
            'fuel_capacity'              => $this->fuel_capacity,
            'payload_capacity'           => $this->payload_capacity,
            'towing_capacity'            => $this->towing_capacity,
            'seating_capacity'           => $this->seating_capacity,
            'weight'                     => $this->weight,
            'length'                     => $this->length,
            'width'                      => $this->width,
            'height'                     => $this->height,
            'cargo_volume'               => $this->cargo_volume,
            'passenger_volume'           => $this->passenger_volume,
            'interior_volume'            => $this->interior_volume,
            'ground_clearance'           => $this->ground_clearance,
            'bed_length'                 => $this->bed_length,
            // Regulatory / compliance
            'emission_standard'          => $this->emission_standard,
            'dpf_equipped'               => $this->dpf_equipped,
            'scr_equipped'               => $this->scr_equipped,
            'gvwr'                       => $this->gvwr,
            'gcwr'                       => $this->gcwr,
            // Lifecycle / service life
            'estimated_service_life_distance'      => $this->estimated_service_life_distance,
            'estimated_service_life_distance_unit' => $this->estimated_service_life_distance_unit,
            'estimated_service_life_months'        => $this->estimated_service_life_months,
            // Financing / values
            'currency'                   => $this->currency,
            'acquisition_cost'           => $this->acquisition_cost,
            'current_value'              => $this->current_value,
            'insurance_value'            => $this->insurance_value,
            'depreciation_rate'          => $this->depreciation_rate,
            'loan_amount'                => $this->loan_amount,
            'loan_number_of_payments'    => $this->loan_number_of_payments,
            'loan_first_payment'         => $this->loan_first_payment,
            // Dates
            'purchased_at'               => $this->purchased_at,
            'lease_expires_at'           => $this->lease_expires_at,
            'deleted_at'                 => $this->deleted_at,
            'updated_at'                 => $this->updated_at,
            'created_at'                 => $this->created_at,
            // Location & telematics
            'location'                   => data_get($this, 'location', new Point(0, 0)),
            'heading'                    => (int) data_get($this, 'heading', 0),
            'altitude'                   => (int) data_get($this, 'altitude', 0),
            'speed'                      => (int) data_get($this, 'speed', 0),
            'telematics'                 => data_get($this, 'telematics'),
            // Specs and details
            'vin_data'                    => data_get($this, 'vin_data', Utils::createObject()),
            'specs'                       => data_get($this, 'specs', Utils::createObject()),
            'details'                     => data_get($this, 'details', Utils::createObject()),
            // Notes & meta
            'notes'                      => $this->notes,
            'meta'                       => data_get($this, 'meta', Utils::createObject()),
        ];
    }
}
