<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateFuelReportRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'driver'          => ['required'],
            'odometer'        => ['required'],
            'volume'          => ['required'],
            'metric_unit'     => ['nullable'],
            'location'        => ['nullable'],
            'amount'          => ['nullable'],
        ];
    }
}
