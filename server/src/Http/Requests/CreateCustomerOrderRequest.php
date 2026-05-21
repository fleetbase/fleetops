<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

/**
 * Validates the body of `POST /v1/customers/orders`.
 *
 * The portal supplies a lightweight order shape (item + weight + value + mode
 * + pickup/dropoff). The controller maps it into a full Fleet-Ops Order via
 * the normal payload/entities scaffolding before passing to OrderController.
 */
class CreateCustomerOrderRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential')
            || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'item'             => 'required|string',
            'weight'           => 'required|numeric|min:0',
            'weight_unit'      => 'nullable|string|in:lb,kg,g,oz',
            'value'            => 'required|numeric|min:0',
            'currency'         => 'nullable|string|size:3',
            'category'         => 'nullable|string',
            'mode'             => 'nullable|string|in:Ocean,Air,Ground',
            'delivery'         => 'nullable|boolean',
            'notes'            => 'nullable|string|max:2000',
            'scheduled_at'     => 'nullable|date',
            'pickup'           => 'nullable|array',
            'pickup.name'      => 'nullable|string',
            'pickup.street1'   => 'nullable|string',
            'pickup.city'      => 'nullable|string',
            'pickup.province'  => 'nullable|string',
            'pickup.postal_code' => 'nullable|string',
            'pickup.country'   => 'nullable|string',
            'dropoff'          => 'nullable|array',
            'dropoff.name'     => 'nullable|string',
            'dropoff.street1'  => 'nullable|string',
            'dropoff.city'     => 'nullable|string',
            'dropoff.province' => 'nullable|string',
            'dropoff.postal_code' => 'nullable|string',
            'dropoff.country'  => 'nullable|string',
            'meta'             => 'nullable|array',
        ];
    }
}
