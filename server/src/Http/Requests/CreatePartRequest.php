<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreatePartRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'sku'              => ['nullable', 'string'],
            'name'             => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'manufacturer'     => ['nullable', 'string'],
            'model'            => ['nullable', 'string'],
            'serial_number'    => ['nullable', 'string'],
            'barcode'          => ['nullable', 'string'],
            'description'      => ['nullable', 'string'],
            'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'unit_cost'        => ['nullable'],
            'msrp'             => ['nullable'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'asset_type'       => ['nullable', 'string'],
            'asset'            => ['nullable', 'required_with:asset_type', 'string'],
            'type'             => ['nullable', 'string'],
            'status'           => ['nullable', 'string'],
            'vendor'           => ['nullable', 'string'],
            'warranty'         => ['nullable', 'string'],
            'photo'            => ['nullable', 'string'],
            'specs'            => ['nullable', 'array'],
            'meta'             => ['nullable', 'array'],
        ];
    }
}
