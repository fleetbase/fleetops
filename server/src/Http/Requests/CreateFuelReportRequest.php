<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateFuelReportRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // requiredIf(POST), not required: UpdateFuelReportRequest extends this class, so a
        // flat `required` made every partial update fail — PUT /v1/fuel-reports/{id} with
        // just {"status": "approved"} answered "The driver field is required."
        //
        // `driver` is the clearest case: the update action's $request->only() list does not
        // even include it, so validation demanded a field the endpoint then discarded.
        // CreateFuelTransactionRequest solves the same create/update inheritance with
        // Rule::requiredIf(isMethod('POST')); a plain conditional is used here because
        // illuminate/validation is not autoloadable in the package's test harness.
        $requiredOnCreate = $this->isMethod('POST') ? ['required'] : ['sometimes'];

        return [
            'driver'          => $requiredOnCreate,
            'odometer'        => $requiredOnCreate,
            'volume'          => $requiredOnCreate,
            'metric_unit'     => ['nullable'],
            'location'        => ['nullable'],
            'amount'          => ['nullable'],
        ];
    }
}
