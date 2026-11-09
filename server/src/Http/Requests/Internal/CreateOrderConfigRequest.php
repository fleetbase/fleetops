<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigActions;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigPolicies;
use Fleetbase\FleetOps\Support\OrderConfig\OrderConfigTriggers;
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
        return array_merge([
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
            'triggers'                 => ['nullable', 'array'],
            'triggers.*.event'         => ['required', 'string', Rule::in(OrderConfigTriggers::EVENTS)],
            'triggers.*.action'        => ['required', 'string', Rule::in(OrderConfigActions::names())],
            'triggers.*.params'        => ['nullable', 'array'],
            'triggers.*.options'       => ['nullable', 'array'],
            'triggers.*.when'          => ['nullable', 'array'],
        ], OrderConfigPolicies::rules());
    }
}
