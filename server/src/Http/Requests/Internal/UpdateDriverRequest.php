<?php

namespace Fleetbase\FleetOps\Http\Requests\Internal;

use Fleetbase\Support\Auth;

class UpdateDriverRequest extends CreateDriverRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->canUpdateDriver();
    }

    protected function canUpdateDriver(): bool
    {
        return Auth::can('fleet-ops update driver');
    }
}
