<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Http\Requests\Concerns\ScopesPublicRelationRules;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateDriverRequest extends FleetbaseRequest
{
    use ScopesPublicRelationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return request()->is('navigator/v1/*') || request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isCreating = $this->isMethod('POST');

        return [
            'name' => [Rule::requiredIf($isCreating), 'nullable', 'string', 'max:191'],

            /*
             * Email and phone are optional.
             *
             * An operational fleet record often has neither: a subcontracted or
             * yard-only driver may have no company mailbox and no handset. The
             * previous contract forced both, and the only way to satisfy it was
             * to invent an address or a number — which then sits in the tenant's
             * user table looking real, can be mailed to, and blocks the genuine
             * value later. Both are still validated and still unique when they
             * are supplied; a driver created without them simply cannot sign in
             * to Navigator until credentials are added.
             */
            'email'    => ['nullable', Rule::when($this->filled('email'), ['email']), Rule::when($isCreating, [Rule::unique('users')->whereNull('deleted_at')])],
            'phone'    => ['nullable', 'string', Rule::when($isCreating, [Rule::unique('users')->whereNull('deleted_at')])],
            'password' => 'nullable|string',
            'timezone' => 'nullable|string|max:64',

            // Identity
            'internal_id'            => 'nullable|string|max:191',
            'drivers_license_number' => 'nullable|string|max:191',
            'license_expiry'         => 'nullable|date',
            'photo'                  => 'nullable|string',

            // Operational
            'country'        => 'nullable|size:2',
            'currency'       => 'nullable|string|size:3',
            'city'           => 'nullable|string|max:191',
            'online'         => 'nullable|boolean',
            'current_status' => 'nullable|string|max:64',
            'status'         => 'nullable|string|in:active,available,inactive',
            'heading'        => 'nullable|numeric',
            'bearing'        => 'nullable|numeric',
            'altitude'       => 'nullable|numeric',
            'speed'          => 'nullable|numeric',
            'location'       => ['nullable', new ResolvablePoint()],
            'latitude'       => ['nullable', 'required_with:longitude'],
            'longitude'      => ['nullable', 'required_with:latitude'],

            // Structured / orchestrator
            'meta'              => 'nullable|array',
            'skills'            => 'nullable|array',
            'skills.*'          => 'string',
            'max_travel_time'   => 'nullable|integer|min:0',
            'max_distance'      => 'nullable|integer|min:0',
            'time_window_start' => 'nullable|date_format:H:i,H:i:s',
            'time_window_end'   => 'nullable|date_format:H:i,H:i:s',

            // Relationships, resolved from public ids inside the caller's company
            'vehicle' => ['nullable', 'string', 'starts_with:vehicle_', $this->existsInCompany('vehicles')],
            'vendor'  => $this->publicRelationRules('vendors'),
            'job'     => $this->publicRelationRules('orders'),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'email' => 'email address',
            'phone' => 'phone number',
        ];
    }
}
