<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ComputableAlgo;
use Illuminate\Validation\Rule;

class CreateServiceRateRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'service_name'                  => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'service_type'                  => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'service_area'                  => 'exists:service_areas,public_id',
            'zone'                          => 'exists:zones,public_id',
            'rate_calculation_method'       => 'required|string|in:per_km,fixed_meter,algo',
            'currency'                      => 'required|size:3',
            'base_fee'                      => 'integer',
            'per_km_flat_rate_fee'          => [Rule::requiredIf($this->input(['rate_calculation_method']) === 'rate_calculation_method'), 'integer'],
            'meter_fees'                    => [Rule::requiredIf($this->input(['rate_calculation_method']) === 'fixed_meter'), 'array'],
            'meter_fees.*.distance'         => 'integer',
            'meter_fees.*.fee'              => 'integer',
            'algorithm'                     => [Rule::requiredIf($this->input(['rate_calculation_method']) === 'algo'), new ComputableAlgo(), 'string'],
            'has_cod_fee'                   => 'boolean',
            'cod_calculation_method'        => [Rule::requiredIf(Utils::isTrue($this->input(['has_cod_fee']))), 'in:percentage,flat'],
            'cod_flat_fee'                  => [Rule::requiredIf($this->input(['cod_calculation_method']) === 'flat'), 'integer'],
            'cod_percent'                   => [Rule::requiredIf($this->input(['cod_calculation_method']) === 'percentage'), 'integer'],
            'has_peak_hours_fee'            => 'boolean',
            'peak_hours_calculation_method' => [Rule::requiredIf(Utils::isTrue($this->input(['has_peak_hours']))), 'in:percentage,flat'],
            'peak_hours_flat_fee'           => [Rule::requiredIf($this->input(['peak_hours_calculation_method']) === 'flat'), 'integer'],
            'peak_hours_percent'            => [Rule::requiredIf($this->input(['peak_hours_calculation_method']) === 'percentage'), 'integer'],
            'peak_hours_start'              => [Rule::requiredIf(Utils::isTrue($this->input(['has_peak_hours']))), 'date_format:H:i'],
            'peak_hours_end'                => [Rule::requiredIf(Utils::isTrue($this->input(['has_peak_hours']))), 'date_format:H:i'],
            'duration_terms'                => 'string',
            'estimated_days'                => 'numeric',
        ];
    }
}
