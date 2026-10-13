<?php

namespace Fleetbase\FleetOps\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Fleetbase\Models\Company::class;
    }

    /**
     * @return HasMany
     */
    public static function drivers()
    {
        return function (): HasMany {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasMany(Driver::class);
        };
    }
}
