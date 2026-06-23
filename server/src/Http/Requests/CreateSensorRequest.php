<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateSensorRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'name'                 => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'type'                 => ['nullable', 'string'],
            'internal_id'          => ['nullable', 'string'],
            'imei'                 => ['nullable', 'string'],
            'imsi'                 => ['nullable', 'string'],
            'firmware_version'     => ['nullable', 'string'],
            'serial_number'        => ['nullable', 'string'],
            'last_position'        => ['nullable', new ResolvablePoint()],
            'latitude'             => ['nullable', 'required_with:longitude'],
            'longitude'            => ['nullable', 'required_with:latitude'],
            'unit'                 => ['nullable', 'string'],
            'min_threshold'        => ['nullable', 'numeric'],
            'max_threshold'        => ['nullable', 'numeric'],
            'threshold_inclusive'  => ['nullable', 'boolean'],
            'last_reading_at'      => ['nullable', 'date'],
            'last_value'           => ['nullable'],
            'calibration'          => ['nullable', 'array'],
            'report_frequency_sec' => ['nullable', 'integer'],
            'status'               => ['nullable', 'string'],
            'device'               => ['nullable', 'string'],
            'telematic'            => ['nullable', 'string'],
            'warranty'             => ['nullable', 'string'],
            'photo'                => ['nullable', 'string'],
            'sensorable_type'      => ['nullable', 'string'],
            'sensorable'           => ['nullable', 'required_with:sensorable_type', 'string'],
            'meta'                 => ['nullable', 'array'],
        ];
    }
}
