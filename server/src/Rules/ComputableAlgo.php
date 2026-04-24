<?php

namespace Fleetbase\FleetOps\Rules;

use Fleetbase\FleetOps\Support\Algo;
use Illuminate\Contracts\Validation\Rule;

class ComputableAlgo implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     *
     * @return bool
     */
    public function passes($attribute, $value)
    {
        return Algo::isComputable($value);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'Algorithm provided is not computable.';
    }
}
