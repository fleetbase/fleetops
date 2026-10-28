<?php

namespace Fleetbase\FleetOps\Http\Requests;

class UpdateOrderRequest extends CreateOrderRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * An update edits an order that already has a payload, so a `payload` object may carry only
     * the parts that change: entities alone, a new dropoff alone, and so on. The route endpoints
     * that creation requires are therefore optional here. An empty waypoints list is allowed:
     * the controller treats it as "remove the intermediate stops".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $validations = parent::rules();

        if ($this->has('payload') && $this->isArray('payload')) {
            $validations['payload']           = 'array';
            $validations['payload.pickup']    = 'nullable';
            $validations['payload.dropoff']   = 'nullable';
            $validations['payload.waypoints'] = 'array';
        }

        return $validations;
    }
}
