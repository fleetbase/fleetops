<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

/**
 * Validates the body of `POST /v1/customers/orders`.
 *
 * Mirrors the canonical Fleet-Ops Order create shape used by the operator
 * `POST /v1/orders` endpoint: `type` / `order_config`, `scheduled_at`,
 * `notes`, `meta`, and either a nested `payload` or top-level
 * `pickup` / `dropoff` / `return` / `waypoints` / `entities`. Place and
 * Entity sub-objects accept the standard Fleet-Ops Place / Entity field
 * shapes — no client-portal aliases are recognized.
 *
 * `customer` is intentionally not accepted: the controller forces
 * `customer_uuid` from the authenticated Customer-Token. `status`,
 * `driver`, `vehicle`, `facilitator`, `dispatch` and similar operator-tier
 * fields are also out of scope for the customer surface.
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
            'type'             => 'nullable|string',
            'order_config'     => 'nullable|string',
            'scheduled_at'     => 'nullable|date',
            'notes'            => 'nullable|string|max:2000',
            'internal_id'      => 'nullable|string|max:191',
            'meta'             => 'nullable|array',

            // payload may be an object OR a payload public_id string
            'payload'          => 'nullable',

            // Top-level alternatives when `payload` is not provided. Each
            // Place sub-object accepts the standard Place fillable shape.
            'pickup'           => 'nullable',
            'dropoff'          => 'nullable',
            'return'           => 'nullable',
            'waypoints'        => 'nullable|array',
            'entities'         => 'nullable|array',
            'entities.*.name'           => 'nullable|string',
            'entities.*.description'    => 'nullable|string',
            'entities.*.weight'         => 'nullable|numeric|min:0',
            'entities.*.weight_unit'    => 'nullable|string',
            'entities.*.declared_value' => 'nullable|numeric|min:0',
            'entities.*.currency'       => 'nullable|string|size:3',
            'entities.*.meta'           => 'nullable|array',
        ];
    }
}
