<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ExistsInAny;

class CreateTrackingNumberRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'region' => 'required|string',
            'owner'  => ['required', new ExistsInAny(['orders', 'entities'], 'public_id')],
            'type'   => 'nullable|in:city,province,country',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
