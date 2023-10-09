<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreatePayloadRequest extends FleetbaseRequest
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
            'pickup'             => 'required',
            'dropoff'            => 'required',
            'return'             => 'nullable',
            'waypoints'          => 'nullable|array',
            'type'               => 'required',
            'cod_currency'       => [Rule::requiredIf($this->has(['cod_amount'])), 'size:3'],
            'cod_payment_method' => [Rule::requiredIf($this->has(['cod_amount'])), 'in:card,check,cash,bank_transfer'],
        ];
    }
}
