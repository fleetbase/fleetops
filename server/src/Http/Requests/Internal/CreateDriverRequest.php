<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest as CreateDriverApiRequest;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\FleetOps\Rules\ResolvableVehicle;
use Fleetbase\Support\Auth;
use Illuminate\Validation\Rule;

class CreateDriverRequest extends CreateDriverApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::can('fleet-ops create driver');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isCreating = $this->isMethod('POST');

        return [
            // The driver profile manages its login account: name, email and phone
            // are proxied to it. Email/phone availability is checked against the
            // account the profile resolves to (see ProfileAccountManager), because a
            // team member of the organization with the same email or phone is linked
            // rather than rejected as a duplicate.
            'name'                   => [Rule::requiredIf($isCreating), 'nullable', 'string', 'max:255'],
            'email'                  => ['nullable', Rule::when($this->filled('email'), ['email'])],
            'phone'                  => ['nullable', 'string'],

            // Optional fields
            'password'               => 'nullable|string|min:8',
            'drivers_license_number' => 'nullable|string|max:255',
            'internal_id'            => 'nullable|string|max:255',
            'country'                => 'nullable|string|size:2',
            'city'                   => 'nullable|string|max:255',
            'vehicle'                => ['nullable', new ResolvableVehicle()],
            'license_expiry'         => 'nullable|date',
            'status'                 => 'nullable|string|in:active,available,inactive',
            'vendor'                 => 'nullable|exists:vendors,public_id',
            'job'                    => 'nullable|exists:orders,public_id',
            'location'               => ['nullable', new ResolvablePoint()],
            'latitude'               => ['nullable', 'required_with:longitude', 'numeric'],
            'longitude'              => ['nullable', 'required_with:latitude', 'numeric'],

            // Photo/avatar
            'photo_uuid'             => 'nullable|exists:files,uuid',
            'avatar_uuid'            => 'nullable|exists:files,uuid',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name'                   => 'driver name',
            'email'                  => 'email address',
            'phone'                  => 'phone number',
            'drivers_license_number' => 'driver\'s license number',
            'internal_id'            => 'internal ID',
            'photo_uuid'             => 'photo',
            'avatar_uuid'            => 'avatar',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required'  => 'Driver name is required.',
            'email.email'    => 'Please provide a valid email address.',
            'password.min'   => 'Password must be at least 8 characters.',
        ];
    }
}
