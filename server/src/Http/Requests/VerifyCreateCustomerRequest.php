<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

/**
 * Validates the body of `POST /v1/customers/request-creation-code`.
 */
class VerifyCreateCustomerRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential')
            || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'mode'     => 'required|in:email,sms',
            'identity' => 'required|string',
        ];
    }
}
