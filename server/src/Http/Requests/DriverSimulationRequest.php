<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Rules\ResolvablePoint;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class DriverSimulationRequest extends FleetbaseRequest
{
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
            'start' => [Rule::requiredIf(
                function () {
                    return $this->missing('order');
                }
            ), new ResolvablePoint()],
            'end' => [Rule::requiredIf(
                function () {
                    return $this->missing('order');
                }
            ), new ResolvablePoint()],
            'order' => [Rule::requiredIf(
                function () {
                    return $this->input('action', 'drive') === 'order';
                }
            ), 'string', 'exists:orders,public_id'],
        ];
    }
}
