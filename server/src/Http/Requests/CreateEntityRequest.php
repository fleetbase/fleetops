<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateEntityRequest extends FleetbaseRequest
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
            'name'            => [Rule::requiredIf($this->isMethod('POST'))],
            'type'            => [Rule::requiredIf($this->isMethod('POST'))],
            'email'           => 'nullable|email',
            'weight'          => 'nullable',
            'weight_unit'     => [Rule::requiredIf($this->has('weight')), 'in:g,oz,lb,kg'],
            'length'          => 'nullable',
            'width'           => 'nullable',
            'height'          => 'nullable',
            'dimensions_unit' => [Rule::requiredIf($this->has(['length', 'width', 'height'])), 'in:cm,in,ft,mm,m,yd'],
            'declared_value'  => 'nullable',
            'price'           => 'nullable',
            'sales_price'     => 'nullable',
            'currency'        => [Rule::requiredIf($this->has(['declared_value', 'price', 'sales_price'])), 'size:3'],
        ];
    }
}
