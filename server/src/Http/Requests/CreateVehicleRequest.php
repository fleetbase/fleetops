<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateVehicleRequest extends FleetbaseRequest
{
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
            'status'    => [
                'nullable',
                Rule::in([
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
                ]),
            ],
            // Validated rather than merely accepted: the model casts odometer
            // to an integer, so an unchecked string would be stored as 0 — a
            // vehicle reporting zero miles rather than an error.
            'odometer'      => 'nullable|numeric|min:0',
            'odometer_unit' => 'nullable|string|max:12',
            'vendor'        => 'nullable|exists:vendors,public_id',
            'driver'        => 'nullable|exists:drivers,public_id',
            'location'      => ['nullable', new ResolvablePoint()],
            'latitude'      => ['nullable', 'required_with:longitude'],
            'longitude'     => ['nullable', 'required_with:latitude'],
        ];
    }
}
