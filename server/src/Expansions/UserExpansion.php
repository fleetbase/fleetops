<?php

namespace Fleetbase\FleetOps\Expansions;

use Fleetbase\Build\Expansion;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
     * @return HasOne
     */
    public static function driver()
    {
        return function (): HasOne {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasOne(Driver::class)->without('user');
        };
    }

    /**
     * @return HasOne
     */
    public static function currentDriverSession()
    {
        return function (): HasOne {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->driver()->where('company_uuid', session('company'));
        };
    }

    /**
     * @return HasMany
     */
    public static function driverProfiles()
    {
        return function (): HasMany {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasMany(Driver::class);
        };
    }

    /**
     * @return HasOne
     */
    public static function customer()
    {
        return function (): HasOne {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasOne(Contact::class, 'user_uuid', 'uuid')->where('type', 'customer')->without('user');
        };
    }

    /**
     * @return HasOne
     */
    public static function contact()
    {
        return function (): HasOne {
            /** @var \Illuminate\Database\Eloquent\Model $this */
            return $this->hasOne(Contact::class, 'user_uuid', 'uuid')->without('user');
        };
    }
}
