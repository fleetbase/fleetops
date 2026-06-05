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
            'order'        => ['nullable', 'exists:orders,public_id'],
            'order_uuid'   => ['nullable', 'exists:orders,uuid'],
            'category'     => ['nullable'],
            'type'         => ['nullable'],
            'priority'     => ['nullable'],
            'tags'         => ['nullable', 'array'],
            'tags.*'       => ['string'],
        ];
    }
}
