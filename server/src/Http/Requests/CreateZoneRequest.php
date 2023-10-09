<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateZoneRequest extends FleetbaseRequest
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
            'name' => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            // @todo properly validate boundary param
            'boundary'     => 'required|array',
            'service_area' => 'required|exists:service_areas,public_id',
            'status'       => 'nullable|in:active,inactive',
        ];
    }
}
