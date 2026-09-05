<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Http\Requests\Concerns\ScopesPublicRelationRules;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateVehicleRequest extends FleetbaseRequest
{
    use ScopesPublicRelationRules;

    /**
     * The statuses the public API accepts for a vehicle.
     *
     * `active` is retained even though the model rewrites it to `available`, so
     * clients written against the original contract keep working.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        'active',
        'available',
        'in_use',
        'maintenance',
        'out_of_service',
        'reserved',
        'retired',
        'staging',
        'on_route',
        'idle',
        'cleaning',
        'awaiting_parts',
        'inspection_due',
        'inspection_failed',
        'accident',
        'compliance_hold',
        'stolen',
        'operational',
        'decommissioned',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(static::STATUSES)],

            // Identity and description
            'internal_id'      => 'nullable|string|max:191',
            'name'             => 'nullable|string|max:191',
            'description'      => 'nullable|string',
            'make'             => 'nullable|string|max:191',
            'model'            => 'nullable|string|max:191',
            'model_type'       => 'nullable|string|max:191',
            'year'             => 'nullable|integer|min:1900|max:2100',
            'trim'             => 'nullable|string|max:191',
            'color'            => 'nullable|string|max:64',
            'type'             => 'nullable|string|max:191',
            'class'            => 'nullable|string|max:191',
            'plate_number'     => 'nullable|string|max:191',
            'vin'              => 'nullable|string|max:64',
            'serial_number'    => 'nullable|string|max:191',
            'call_sign'        => 'nullable|string|max:191',
            'fuel_card_number' => 'nullable|string|max:191',

            // Measurement and operation.
            //
            // Validated rather than merely accepted: the model casts odometer
            // to an integer, so an unchecked string would be stored as 0 — a
            // vehicle reporting zero miles rather than an error.
            'odometer'             => 'nullable|numeric|min:0',
            'odometer_unit'        => 'nullable|string|max:12',
            'odometer_at_purchase' => 'nullable|numeric|min:0',
            'measurement_system'   => 'nullable|string|max:32',
            'fuel_type'            => 'nullable|string|max:64',
            'fuel_volume_unit'     => 'nullable|string|max:12',
            'online'               => 'nullable|boolean',

            // Body, capacity and dimensions
            'transmission'      => 'nullable|string|max:64',
            'body_type'         => 'nullable|string|max:191',
            'body_sub_type'     => 'nullable|string|max:191',
            'usage_type'        => 'nullable|string|max:64',
            'ownership_type'    => 'nullable|string|max:64',
            'cargo_volume'      => 'nullable|numeric|min:0',
            'passenger_volume'  => 'nullable|numeric|min:0',
            'interior_volume'   => 'nullable|numeric|min:0',
            'weight'            => 'nullable|numeric|min:0',
            'width'             => 'nullable|numeric|min:0',
            'length'            => 'nullable|numeric|min:0',
            'height'            => 'nullable|numeric|min:0',
            'towing_capacity'   => 'nullable|numeric|min:0',
            'payload_capacity'  => 'nullable|numeric|min:0',
            'seating_capacity'  => 'nullable|integer|min:0',
            'ground_clearance'  => 'nullable|numeric|min:0',
            'bed_length'        => 'nullable|numeric|min:0',
            'fuel_capacity'     => 'nullable|numeric|min:0',

            // Lifecycle and financing
            'financing_status'                     => 'nullable|string|max:64',
            'loan_number_of_payments'              => 'nullable|integer|min:0',
            'loan_first_payment'                   => 'nullable|date',
            'loan_amount'                          => 'nullable|numeric|min:0',
            'estimated_service_life_distance_unit' => 'nullable|string|max:12',
            'estimated_service_life_distance'      => 'nullable|integer|min:0',
            'estimated_service_life_months'        => 'nullable|integer|min:0',
            'insurance_value'                      => 'nullable|numeric|min:0',
            'depreciation_rate'                    => 'nullable|numeric',
            'current_value'                        => 'nullable|numeric|min:0',
            'acquisition_cost'                     => 'nullable|numeric|min:0',
            'currency'                             => 'nullable|string|size:3',
            'purchased_at'                         => 'nullable|date',
            'lease_expires_at'                     => 'nullable|date',

            // Regulatory and engine specifications
            'emission_standard'    => 'nullable|string|max:64',
            'dpf_equipped'         => 'nullable|boolean',
            'scr_equipped'         => 'nullable|boolean',
            'gvwr'                 => 'nullable|numeric|min:0',
            'gcwr'                 => 'nullable|numeric|min:0',
            'engine_number'        => 'nullable|string|max:191',
            'engine_model'         => 'nullable|string|max:191',
            'engine_make'          => 'nullable|string|max:191',
            'engine_family'        => 'nullable|string|max:191',
            'engine_configuration' => 'nullable|string|max:191',
            'engine_displacement'  => 'nullable|numeric|min:0',
            'engine_size'          => 'nullable|numeric|min:0',
            'horsepower'           => 'nullable|numeric|min:0',
            'horsepower_rpm'       => 'nullable|integer|min:0',
            'torque'               => 'nullable|numeric|min:0',
            'torque_rpm'           => 'nullable|integer|min:0',
            'number_of_cylinders'  => 'nullable|integer|min:0',
            'cylinder_arrangement' => 'nullable|string|max:64',

            // Structured and descriptive fields
            'specs'   => 'nullable|array',
            'details' => 'nullable|array',
            'notes'   => 'nullable|string',
            'meta'    => 'nullable|array',

            // Orchestrator
            'skills'                   => 'nullable|array',
            'skills.*'                 => 'string',
            'payload_capacity_volume'  => 'nullable|numeric|min:0',
            'payload_capacity_pallets' => 'nullable|integer|min:0',
            'payload_capacity_parcels' => 'nullable|integer|min:0',
            'max_tasks'                => 'nullable|integer|min:0',
            'time_window_start'        => 'nullable|date_format:H:i,H:i:s',
            'time_window_end'          => 'nullable|date_format:H:i,H:i:s',
            'return_to_depot'          => 'nullable|boolean',

            // Relationships, resolved from public ids inside the caller's company
            'vendor'   => $this->publicRelationRules('vendors'),
            'driver'   => $this->publicRelationRules('drivers'),
            'category' => $this->publicRelationRules('categories'),
            'warranty' => $this->publicRelationRules('warranties'),
            'photo'    => 'nullable|string',

            // Location
            'location'  => ['nullable', new ResolvablePoint()],
            'latitude'  => ['nullable', 'required_with:longitude'],
            'longitude' => ['nullable', 'required_with:latitude'],
            'altitude'  => 'nullable|numeric',
            'heading'   => 'nullable|numeric',
            'speed'     => 'nullable|numeric',
        ];
    }
}
