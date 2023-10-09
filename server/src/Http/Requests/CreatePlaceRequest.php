<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ExistsInAny;
use Illuminate\Validation\Rule;

class CreatePlaceRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()
            ->session()
            ->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'     => [Rule::requiredIf($this->isMethod('POST'))],
            'street1'  => [Rule::requiredIf($this->isMethod('POST'))],
            'customer' => ['nullable', new ExistsInAny(['vendors', 'contacts'], 'public_id')],
            'contact'  => ['nullable', new ExistsInAny(['vendors', 'contacts'], 'public_id')],
            'vendor'   => 'nullable|exists:vendors,public_id',
        ];
    }
}
