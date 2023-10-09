<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateVehicleRequest extends FleetbaseRequest
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
            'status' => 'nullable|in:operational,maintenance,decommissioned',
            'vendor' => 'nullable|exists:vendors,public_id',
            'driver' => 'nullable|exists:drivers,public_id',
        ];
    }
}
