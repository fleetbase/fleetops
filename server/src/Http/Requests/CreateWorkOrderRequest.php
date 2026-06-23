<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateWorkOrderRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'code'            => ['nullable', 'string'],
            'subject'         => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'category'        => ['nullable', 'string'],
            'status'          => ['nullable', 'string'],
            'priority'        => ['nullable', 'string'],
            'target_type'     => ['nullable', 'string'],
            'target'          => ['nullable', 'required_with:target_type', 'string'],
            'assignee_type'   => ['nullable', 'string'],
            'assignee'        => ['nullable', 'required_with:assignee_type', 'string'],
            'opened_at'       => ['nullable', 'date'],
            'due_at'          => ['nullable', 'date'],
            'closed_at'       => ['nullable', 'date'],
            'instructions'    => ['nullable', 'string'],
            'checklist'       => ['nullable', 'array'],
            'estimated_cost'  => ['nullable'],
            'approved_budget' => ['nullable'],
            'actual_cost'     => ['nullable'],
            'currency'        => ['nullable', 'string', 'size:3'],
            'cost_breakdown'  => ['nullable', 'array'],
            'cost_center'     => ['nullable', 'string'],
            'budget_code'     => ['nullable', 'string'],
            'meta'            => ['nullable', 'array'],
        ];
    }
}
