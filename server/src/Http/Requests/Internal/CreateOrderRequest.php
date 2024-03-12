<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

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
        return request()->session()->has('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $validations = [
            'order_config_uuid' => 'required',
            'adhoc'             => 'in:true,false,1,0',
            'dispatch'          => ['nullable', 'boolean'],
            'adhoc_distance'    => 'numeric',
            'pod_required'      => 'in:true,false,1,0',
            'pod_method'        => 'in:' . config('api.pod_methods'),
            'scheduled_at'      => ['nullable', 'date'],
            'driver'            => 'nullable|exists:drivers,uuid',
            'service_quote'     => 'nullable|exists:service_quotes,uuid',
            'purchase_rate'     => 'nullable|exists:purchase_rates,uuid',
            'facilitator'       => ['nullable', new ExistsInAny(['vendors', 'contacts', 'integrated_vendors'], ['uuid', 'provider'])],
            'customer'          => ['nullable', new ExistsInAny(['vendors', 'contacts'], 'uuid')],
            'status'            => 'string',
            'type'              => 'string',
        ];

        if ($this->has('payload')) {
            $validations['payload.entities']  = 'array';
            $validations['payload.waypoints'] = 'array';

            if ($this->isArray('payload')) {
                $validations['payload']         = 'required';

                if ($this->missing('payload.waypoints')) {
                    $validations['payload.pickup']  = 'required';
                    $validations['payload.dropoff'] = 'required';
                }

                if ($this->missing(['payload.pickup', 'payload.dropoff'])) {
                    $validations['payload.waypoints'] = 'required|array|min:2';
                }

                $validations['payload.return']  = 'nullable';
            }

            if ($this->isString('payload')) {
                $validations['payload'] = 'required|exists:payloads,uuid';
            }
        }

        if ($this->missing('payload') && $this->isMethod('POST')) {
            if ($this->missing('waypoints')) {
                $validations['pickup']  = 'required';
                $validations['dropoff'] = 'required';
            }

            if ($this->missing(['pickup', 'dropoff'])) {
                $validations['waypoints'] = 'required|array|min:2';
            }
        }

        return $validations;
    }
}
