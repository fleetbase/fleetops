<?php

namespace Fleetbase\FleetOps\Expansions;

use Fleetbase\Build\Expansion;

class UserFilterExpansion implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Fleetbase\Http\Filter\UserFilter::class;
    }

    /**
     * Filter where doesnt have driver within the CURRENT organizations.
     *
     * @return void
     */
    public static function doesntHaveDriver()
    {
        return function () {
            /** @var \Fleetbase\Http\Filter\UserFilter|\Fleetbase\Http\Filter\Filter $this */
            $this->builder->whereDoesntHave('driverProfiles', function ($query) {
                $query->where('company_uuid', session('company'));
            });
        };
    }

    /**
     * @return void
     */
    public static function doesntHaveContact()
    {
        return function () {
            /** @var \Fleetbase\Http\Filter\UserFilter|\Fleetbase\Http\Filter\Filter $this */
            $this->builder->whereDoesntHave('contact', function ($query) {
                $query->where('company_uuid', session('company'));
            });
        };
    }

    /**
     * @return void
     */
    public static function doesntHaveCustomer()
    {
        return function () {
            /** @var \Fleetbase\Http\Filter\UserFilter|\Fleetbase\Http\Filter\Filter $this */
            $this->builder->whereDoesntHave('customer', function ($query) {
                $query->where('company_uuid', session('company'));
            });
        };
    }
}
