<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateTrackingStatusRequest extends FleetbaseRequest
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
            'tracking_number' => [
                'required',
                Rule::exists('tracking_numbers')->where(function ($query) {
                    $query->where('public_id', $this->input('tracking_number'));
                    $query->whereDoesntHave('status', function ($q) {
                        $q->where('code', $this->input('code'));
                    });
                }),
            ],
            'status'  => 'required|string',
            'details' => 'required|string',
            'code'    => 'required|string',
        ];
    }
}
