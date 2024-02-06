<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\User;

class DriverObserver
{
    /**
     * Handle the Driver "creating" event.
     *
     * @return void
     */
    public function creating(Driver $driver)
    {
        // if the driver has no default location set one
        if (empty($driver->location)) {
            $driver->location = new Point(0, 0);
        }
    }

    /**
     * Handle the Driver "deleting" event.
     *
     * @return void
     */
    public function deleting(Driver $driver)
    {
        // unassign the vehicle from the driver
        $driver->vehicle_uuid = null;
    }

    /**
     * Handle the Driver "deleted" event.
     *
     * @return void
     */
    public function deleted(Driver $driver)
    {
        // if the driver is deleted, delete their user account assosciated as well
        if ($driver->user->companies()->count() === 1) {
            User::where(['uuid' => $driver->user_uuid, 'type' => 'driver'])->delete();
        } else {
            CompanyUser::where(['user_uuid' => $driver->user_uuid, 'company_uuid' => session('company')])->delete();
        }

        // also unassign them from any order they are assigned to
        Order::where(['driver_assigned_uuid' => $driver->uuid])->update(['driver_assigned_uuid' => null]);
    }
}
