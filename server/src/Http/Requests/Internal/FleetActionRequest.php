<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

class FleetActionRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'fleet'   => 'string|exists:fleets,uuid',
            'driver'  => 'nullable|string|exists:drivers,uuid',
            'vehicle' => 'nullable|string|exists:vehicles,uuid',
        ];
    }
}
