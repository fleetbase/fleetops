<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\User;
use Grimzy\LaravelMysqlSpatial\Types\Point;

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
        User::where(['uuid' => $driver->user_uuid, 'type' => 'driver'])->delete();

        // also unassign them from any order they are assigned to
        Order::where(['driver_assigned_uui' => $driver->uuid])->update(['driver_assigned_uui' => null]);
    }
}
