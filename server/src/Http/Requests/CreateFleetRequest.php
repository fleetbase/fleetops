<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Http\Requests\Concerns\ScopesPublicRelationRules;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateFleetRequest extends FleetbaseRequest
{
    use ScopesPublicRelationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return request()->session()->has('api_credential');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name'   => [Rule::requiredIf($this->isMethod('POST')), 'nullable', 'string', 'max:191'],
            'color'  => 'nullable|string|max:64',
            'task'   => 'nullable|string|max:191',
            /*
             * Fleet status is not a closed set on this model: the console, the
             * spreadsheet importer and existing integrations all write free-form
             * values, and `active`/`disabled`/`decommissioned` are the console's
             * options rather than a schema constraint. Restricting the public API
             * to a subset would leave it unable to express a state the console can.
             */
            'status'       => 'nullable|string|max:64',
            'service_area' => $this->publicRelationRules('service_areas'),
            'zone'         => $this->publicRelationRules('zones'),
            'vendor'       => $this->publicRelationRules('vendors'),
            'parent_fleet' => $this->publicRelationRules('fleets'),
            'photo'        => 'nullable|string',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'service_area' => 'service area',
            'parent_fleet' => 'parent fleet',
        ];
    }
}
