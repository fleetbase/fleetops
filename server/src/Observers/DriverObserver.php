<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\FleetOps\Support\ProfileAccountManager;
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

        // Delete the driver's managed login account, which frees its email and
        // phone. A team member's account linked to the driver is left alone.
        ProfileAccountManager::releaseForProfile($this->findDriverUser($driver), $driver->company_uuid);

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
        return $driver->user_uuid ? User::where('uuid', $driver->user_uuid)->first() : null;
    }
}
