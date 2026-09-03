<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateTrailerRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'name'                => [Rule::requiredIf($this->isMethod('POST')), 'string', 'max:255'],
            'description'         => ['nullable', 'string'],
            'code'                => ['nullable', 'string', 'max:255'],
            'type'                => ['nullable', Rule::in(['dry_van', 'reefer', 'flatbed', 'step_deck', 'lowboy', 'tanker', 'bulk', 'dump', 'chassis', 'curtain_side', 'car_carrier', 'livestock', 'logging', 'dolly', 'specialty', 'other'])],
            'body_type'           => ['nullable', 'string', 'max:255'],
            'status'              => ['nullable', Rule::in(['available', 'in_use', 'maintenance', 'out_of_service', 'retired'])],
            'vin'                 => ['nullable', 'string', 'max:255'],
            'plate_number'        => ['nullable', 'string', 'max:255'],
            'serial_number'       => ['nullable', 'string', 'max:255'],
            'make'                => ['nullable', 'string', 'max:255'],
            'model'               => ['nullable', 'string', 'max:255'],
            'year'                => ['nullable', 'integer', 'min:1880', 'max:' . (now()->year + 2)],
            'color'               => ['nullable', 'string', 'max:255'],
            'usage_type'          => ['nullable', 'string', 'max:255'],
            'category'            => ['nullable', 'string'], 'vendor' => ['nullable', 'string'], 'warranty' => ['nullable', 'string'], 'photo' => ['nullable', 'string'],
            'location'            => ['nullable', new ResolvablePoint()], 'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude'           => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'length'              => ['nullable', 'numeric', 'min:0'], 'width' => ['nullable', 'numeric', 'min:0'], 'height' => ['nullable', 'numeric', 'min:0'],
            'tare_weight'         => ['nullable', 'numeric', 'min:0'], 'gvwr' => ['nullable', 'numeric', 'min:0'], 'payload_capacity' => ['nullable', 'numeric', 'min:0'],
            'cargo_volume'        => ['nullable', 'numeric', 'min:0'], 'axle_count' => ['nullable', 'integer', 'min:0'], 'tire_count' => ['nullable', 'integer', 'min:0'],
            'door_count'          => ['nullable', 'integer', 'min:0'], 'abs_equipped' => ['nullable', 'boolean'], 'ebs_equipped' => ['nullable', 'boolean'],
            'refrigerated'        => ['nullable', 'boolean'], 'temperature_min' => ['nullable', 'numeric'], 'temperature_max' => ['nullable', 'numeric', 'gte:temperature_min'],
            'reefer_engine_hours' => ['nullable', 'numeric', 'min:0'],
            'measurement_system'  => ['nullable', Rule::in(['metric', 'imperial'])], 'odometer' => ['nullable', 'numeric', 'min:0'],
            'odometer_unit'       => ['nullable', Rule::in(['km', 'mi', 'miles', 'kilometers'])],
            'coupling_type'       => ['nullable', 'string', 'max:255'], 'brake_type' => ['nullable', 'string', 'max:255'],
            'ownership_type'      => ['nullable', Rule::in(['owned', 'leased', 'financed', 'rented'])],
            'purchased_at'        => ['nullable', 'date'], 'lease_expires_at' => ['nullable', 'date'],
            'financing_status'    => ['nullable', 'string', 'max:255'], 'currency' => ['nullable', 'string', 'size:3'],
            'acquisition_cost'    => ['nullable', 'numeric', 'min:0'], 'current_value' => ['nullable', 'numeric', 'min:0'],
            'insurance_value'     => ['nullable', 'numeric', 'min:0'], 'depreciation_rate' => ['nullable', 'numeric', 'min:0'],
            'capacity'            => ['nullable', 'array'], 'specs' => ['nullable', 'array'], 'attributes' => ['nullable', 'array'], 'notes' => ['nullable', 'string'],
        ];
    }
}
