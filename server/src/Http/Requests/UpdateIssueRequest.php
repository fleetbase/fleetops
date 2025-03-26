<?php

namespace Fleetbase\FleetOps\Http\Requests;

class UpdateIssueRequest extends CreateIssueRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'report'       => ['required'],
            'category'     => ['nullable'],
            'type'         => ['nullable'],
            'priority'     => ['nullable'],
        ];
    }
}
