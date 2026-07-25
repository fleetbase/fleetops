<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;

class VehicleObserver
{
    /**
     * Handle the Vehicle "created" event.
     *
     * @return void
     */
    public function created(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = $this->getDriverIdentifier();

        if ($identifier) {
            $driver = $this->findDriver($identifier);

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle);

                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }

        $this->invalidateLiveCache();
    }

    /**
     * Handle the Vehicle "updated" event.
     *
     * @return void
     */
    public function updating(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = $this->getDriverIdentifier();

        if ($identifier) {
            $driver = $this->findDriver($identifier);

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle, false);

                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }

        $this->invalidateLiveCache();
    }

    /**
     * Handle the Vehicle "deleted" event.
     *
     * @return void
     */
    public function deleted(Vehicle $vehicle)
    {
        // Unassign the deleted vehicle from matching driver/(s)
        $this->deleteDriversAssignedTo($vehicle);

        $this->invalidateLiveCache();
    }

    protected function getDriverIdentifier(): ?string
    {
        return request()->or(['driver_uuid', 'vehicle.driver_uuid', 'vehicle.driver.uuid']);
    }

    protected function findDriver(string $identifier): ?Driver
    {
        return Driver::where('uuid', $identifier)->whereNull('deleted_at')->withoutGlobalScopes()->first();
    }

    protected function deleteDriversAssignedTo(Vehicle $vehicle): mixed
    {
        return Driver::where(['vehicle_uuid' => $vehicle->uuid])->delete();
    }

    protected function invalidateLiveCache(): void
    {
        LiveCacheService::invalidateMultiple(['vehicles', 'operations-monitor']);
    }
}
