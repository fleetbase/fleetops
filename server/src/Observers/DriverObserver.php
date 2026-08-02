<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
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
     * Handle the Driver "created" event.
     *
     * @return void
     */
    public function created(Driver $driver)
    {
        $this->invalidateLiveCache();
    }

    /**
     * Handle the Driver "updated" event.
     *
     * @return void
     */
    public function updated(Driver $driver)
    {
        $this->invalidateLiveCache();
    }

    /**
     * Handle the Driver "deleting" event.
     *
     * @return void
     */
    public function deleting(Driver $driver)
    {
        // Unassign the vehicle from the driver
        $driver->vehicle_uuid = null;
    }

    /**
     * Handle the Driver "deleted" event.
     *
     * @return void
     */
    public function deleted(Driver $driver)
    {
        // Unassign them from any order they are assigned to
        $this->unassignOrders($driver);

        // If the driver had a user account with the role driver and type user delete it
        $user = $this->findDriverUser($driver);
        if ($user && $user->hasRole('Driver')) {
            $user->delete();
        }

        $this->invalidateLiveCache();
    }

    protected function invalidateLiveCache(): void
    {
        LiveCacheService::invalidateMultiple(['drivers', 'operations-monitor']);
    }

    protected function unassignOrders(Driver $driver): int
    {
        return Order::where(['driver_assigned_uuid' => $driver->uuid])->update(['driver_assigned_uuid' => null]);
    }

    protected function findDriverUser(Driver $driver): ?User
    {
        return User::where(['uuid' => $driver->user_uuid, 'type' => 'user'])->first();
    }
}
