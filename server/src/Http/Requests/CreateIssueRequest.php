<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateIssueRequest extends FleetbaseRequest
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
            'driver'       => ['required'],
            'location'     => ['required'],
            'order'        => ['nullable', 'exists:orders,public_id'],
            'order_uuid'   => ['nullable', 'exists:orders,uuid'],
            'report'       => ['required'],
            'category'     => ['nullable'],
            'type'         => ['nullable'],
            'priority'     => ['nullable'],
            'tags'         => ['nullable', 'array'],
            'tags.*'       => ['string'],
        ];
    }
}
