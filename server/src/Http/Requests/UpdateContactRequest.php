<?php

namespace Fleetbase\FleetOps\Http\Requests;

class UpdateContactRequest extends CreateContactRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'name'  => 'required',
            'email' => 'nullable|email',
            'phone' => 'nullable',
        ];
    }
}
