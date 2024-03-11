<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;

class CreateIssueRequest extends FleetbaseRequest
{
    public function rules()
    {
        return [
            'driver'       => ['required'],
            'location'     => ['required'],
            'category'     => ['nullable'],
            'type'         => ['nullable'],
            'priority'     => ['nullable'],
        ];
    }
}
