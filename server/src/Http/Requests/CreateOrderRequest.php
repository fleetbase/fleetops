<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Rules\ExistsInAny;

class CreateOrderRequest extends FleetbaseRequest
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
        $validations = [
            'adhoc'          => 'in:true,false,1,0',
            'dispatch'       => 'boolean',
            'adhoc_distance' => 'numeric',
            'pod_required'   => 'in:true,false,1,0',
            'pod_method'     => 'in:' . config('api.pod_methods'),
            'scheduled_at'   => 'date',
            'driver'         => 'nullable|exists:drivers,public_id',
            'service_quote'  => 'nullable|exists:service_quotes,public_id',
            'purchase_rate'  => 'nullable|exists:purchase_rates,public_id',
            'facilitator'    => ['nullable', new ExistsInAny(['vendors', 'contacts', 'integrated_vendors'], ['public_id', 'provider'])],
            'customer'       => ['nullable', new ExistsInAny(['vendors', 'contacts'], 'public_id')],
            'status'         => 'string',
            'type'           => 'string',
        ];

        if ($this->has('payload')) {
            $validations['payload.entities']  = 'array';
            $validations['payload.waypoints'] = 'array';

            if ($this->isArray('payload')) {
                $validations['payload']         = 'required';
                $validations['payload.pickup']  = 'required';
                $validations['payload.dropoff'] = 'required';
                $validations['payload.return']  = 'nullable';
            }

            if ($this->isString('payload')) {
                $validations['payload'] = 'required|exists:payloads,public_id';
            }
        }

        if ($this->missing('payload') && $this->isMethod('POST')) {
            $validations['pickup']  = 'required';
            $validations['dropoff'] = 'required';
        }

        return $validations;
    }
}
