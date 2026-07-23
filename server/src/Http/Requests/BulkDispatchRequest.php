<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class BulkDispatchRequest extends FleetbaseRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->session()->has('user');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
        ];
    }

    /**
     * Get the validation rules error messages.
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Please provide a resource ID.',
            'ids.array'    => 'Please provide multiple resource ID\'s.',
        ];
    }
}
