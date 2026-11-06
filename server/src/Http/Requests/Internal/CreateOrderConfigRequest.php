<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Support\Auth;
use Illuminate\Validation\Rule;

class CreateOrderConfigRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Auth::can('fleet-ops create order-config');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                Rule::unique('order_configs', 'name')
                    ->where('company_uuid', request()->session()->get('company'))->whereNull('deleted_at'),
            ],
            'key' => [
                'required',
                Rule::unique('order_configs', 'key')
                    ->where('company_uuid', request()->session()->get('company'))->whereNull('deleted_at'),
            ],
            'adhoc_strategy'        => ['nullable', 'string', Rule::in(AdhocDispatch::names())],
            'adhoc_offer_timeout'   => ['nullable', 'integer', 'min:' . AdhocDispatch::MIN_OFFER_TIMEOUT, 'max:' . AdhocDispatch::MAX_OFFER_TIMEOUT],
            'customer_orders_adhoc' => ['nullable', 'boolean'],
            'match_vehicle_type'    => ['nullable', 'boolean'],
        ];
    }
}
