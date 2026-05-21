<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the body of `POST /v1/customers` (final account creation step).
 *
 * Expects the verification code previously requested via
 * `POST /v1/customers/request-creation-code`.
 */
class CreateCustomerRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential')
            || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'identity' => 'required|string',
            'code'     => 'required|exists:verification_codes,code',
            'name'     => 'required|string',
            'password' => 'required|string|min:8',
            'email'    => [
                'email', 'nullable',
                Rule::unique('contacts')->where(function ($query) {
                    $query->where('company_uuid', session('company'));

                    return $query->whereNull('deleted_at');
                }),
            ],
            'phone' => [
                'nullable', 'string',
                Rule::unique('contacts')->where(function ($query) {
                    $query->where('company_uuid', session('company'));

                    return $query->whereNull('deleted_at');
                }),
            ],
            'meta'  => 'nullable|array',
        ];
    }
}
