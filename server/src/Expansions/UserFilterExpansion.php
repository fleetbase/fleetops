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
     * Filter where user is a driver type or has a driver profile.
     *
     * @return void
     */
    public static function isDriver()
    {
        return function () {
            /** @var \Fleetbase\Http\Filter\UserFilter|\Fleetbase\Http\Filter\Filter $this */
            $this->builder->where(function ($query) {
                $query->where('type', 'driver');
                $query->orwhereHas('driverProfiles', function ($query) {
                    $query->where('company_uuid', session('company'));
                });
            });
        };
    }

    /**
     * Filter where a user is a customer.
     *
     * @return void
     */
    public static function isCustomer()
    {
        return function () {
            /** @var \Fleetbase\Http\Filter\UserFilter|\Fleetbase\Http\Filter\Filter $this */
            $this->builder->where('type', 'customer');
        };
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
