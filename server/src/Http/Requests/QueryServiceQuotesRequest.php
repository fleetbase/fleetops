<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ExistsInAny;

class QueryServiceQuotesRequest extends FleetbaseRequest
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
            'payload'      => 'nullable|exists:payloads,public_id',
            'service_type' => 'nullable|exists:service_rates,service_type',
            'pickup'       => 'nullable|required_without:payload,waypoints',
            'dropoff'      => 'nullable|required_without:payload,waypoints',
            'waypoints'    => 'nullable|array',
            'facilitator'  => ['nullable', new ExistsInAny(['vendors', 'integrated_vendors', 'contacts'], ['public_id', 'provider'])],
            'scheduled_at' => 'nullable|date',
            'cod'          => 'nullable',
            'currency'     => 'nullable',
            'distance'     => 'nullable',
            'time'         => 'nullable',
        ];
    }
}
