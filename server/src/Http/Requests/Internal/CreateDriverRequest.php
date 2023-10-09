<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\FleetOps\Http\Requests\CreateDriverRequest as CreateDriverApiRequest;
use Illuminate\Validation\Rule;

class CreateDriverRequest extends CreateDriverApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return request()->session()->has('company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $isCreating = $this->isMethod('POST');

        return [
            'name'     => [Rule::requiredIf($isCreating)],
            'email'    => [Rule::requiredIf($isCreating), Rule::when($this->filled('email'), ['email']), Rule::when($isCreating, [Rule::unique('users')->whereNull('deleted_at')])],
            'phone'    => [Rule::requiredIf($isCreating), Rule::when($isCreating, [Rule::unique('users')->whereNull('deleted_at')])],
            'password' => 'nullable|string',
            'country'  => 'nullable|size:2',
            'city'     => 'nullable|string',
            // 'vehicle' => 'nullable|exists:vehicles,uuid',
            'status' => 'nullable|string|in:active,inactive',
            // 'vendor' => 'nullable|exists:vendors,public_id',
            'job' => 'nullable|exists:orders,public_id',
        ];
    }
}
