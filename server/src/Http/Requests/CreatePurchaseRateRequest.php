<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ExistsInAny;
use Illuminate\Validation\Rule;

class CreatePurchaseRateRequest extends FleetbaseRequest
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
            'service_quote' => ['required', Rule::exists('service_quotes', 'public_id')->whereNull('deleted_at')],
            'order'         => ['nullable', Rule::exists('orders', 'public_id')->whereNull('deleted_at')],
            'customer'      => ['nullable', new ExistsInAny(['vendors', 'contacts'], 'public_id')],
        ];
    }
}
