<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateEquipmentRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'name'           => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'code'           => ['nullable', 'string'],
            'type'           => ['nullable', 'string'],
            'status'         => ['nullable', 'string'],
            'serial_number'  => ['nullable', 'string'],
            'manufacturer'   => ['nullable', 'string'],
            'model'          => ['nullable', 'string'],
            'warranty'       => ['nullable', 'string'],
            'photo'          => ['nullable', 'string'],
            'equipable_type' => ['nullable', 'string'],
            'equipable'      => ['nullable', 'required_with:equipable_type', 'string'],
            'purchased_at'   => ['nullable', 'date'],
            'purchase_price' => ['nullable'],
            'currency'       => ['nullable', 'string', 'size:3'],
            'meta'           => ['nullable', 'array'],
        ];
    }
}
