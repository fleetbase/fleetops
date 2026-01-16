<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest as CreateDriverApiRequest;
use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Support\Auth;
use Illuminate\Validation\Rule;

class CreateDriverRequest extends CreateDriverApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return Auth::can('fleet-ops create driver');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $isCreating = $this->isMethod('POST');
        $isCreatingWithUser = $this->filled('driver.user_uuid');
        $shouldValidateUserAttributes = $isCreating && !$isCreatingWithUser;

        return [
            // Required fields for driver creation
            'name'                   => [Rule::requiredIf($shouldValidateUserAttributes), 'nullable', 'string', 'max:255'],
            'email'                  => [
                Rule::requiredIf($shouldValidateUserAttributes),
                Rule::when($this->filled('email'), ['email']),
                Rule::when($shouldValidateUserAttributes, [Rule::unique('users')->whereNull('deleted_at')])
            ],
            'phone'                  => [
                Rule::requiredIf($shouldValidateUserAttributes),
                Rule::when($shouldValidateUserAttributes, [Rule::unique('users')->whereNull('deleted_at')])
            ],
            
            // Optional fields
            'password'               => 'nullable|string|min:8',
            'drivers_license_number' => 'nullable|string|max:255',
            'internal_id'            => 'nullable|string|max:255',
            'country'                => 'nullable|string|size:2',
            'city'                   => 'nullable|string|max:255',
            'vehicle'                => 'nullable|string|starts_with:vehicle_|exists:vehicles,public_id',
            'status'                 => 'nullable|string|in:active,inactive',
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
     *
     * @return array
     */
    public function attributes()
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
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'  => 'Driver name is required.',
            'email.required' => 'Email address is required.',
            'email.email'    => 'Please provide a valid email address.',
            'email.unique'   => 'This email address is already registered.',
            'phone.required' => 'Phone number is required.',
            'phone.unique'   => 'This phone number is already registered.',
            'password.min'   => 'Password must be at least 8 characters.',
        ];
    }
}
