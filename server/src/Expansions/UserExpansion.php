<?php

namespace Fleetbase\FleetOps\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\FleetOps\Models\Driver;

class UserExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Fleetbase\Models\User::class;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function driver()
    {
        return function () {
            /* @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasOne(Driver::class)->without('user');
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentDriverSession()
    {
        return function () {
            /* @var \Illuminate\Database\Eloquent\Model $this */
            return $this->driver()->where('company_uuid', session('company'));
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function driverProfiles()
    {
        return function () {
            /* @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasMany(Driver::class)->without('user');
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function customer()
    {
        return function () {
            /* @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasOne(Contact::class)->where('type', 'customer')->without('user');
        };
    }
}
