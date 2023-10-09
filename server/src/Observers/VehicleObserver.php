<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Driver;

class VehicleObserver
{
    /**
     * Handle the Vehicle "created" event.
     *
     * @param  \Fleetbase\FleetOps\Models\Vehicle  $vehicle
     * @return void
     */
    public function created(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = request()->or(['driver_uuid', 'vehicle.driver_uuid', 'vehicle.driver.uuid']);

        if ($identifier) {
            $driver = Driver::where('uuid', $identifier)->whereNull('deleted_at')->withoutGlobalScopes()->first();

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle);

                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }
    }

    /**
     * Handle the Vehicle "updated" event.
     *
     * @param  \Fleetbase\FleetOps\Models\Vehicle  $vehicle
     * @return void
     */
    public function updated(Vehicle $vehicle)
    {
        // assign this vehicle to a driver if the driver has been set
        $identifier = request()->or(['driver_uuid', 'vehicle.driver_uuid', 'vehicle.driver.uuid']);

        if ($identifier) {
            $driver = Driver::where('uuid', $identifier)->whereNull('deleted_at')->withoutGlobalScopes()->first();

            if ($driver) {
                // assign this vehicle to driver
                $driver->assignVehicle($vehicle);
                
                // set driver to vehicle
                $vehicle->setRelation('driver', $driver);
            }
        }
    }

    /**
     * Handle the Vehicle "deleted" event.
     *
     * @param  \Fleetbase\FleetOps\Models\Vehicle  $vehicle
     * @return void
     */
    public function deleted(Vehicle $vehicle)
    {
        // Unassign the deleted vehicle from matching driver/(s)
        Driver::where(['vehicle_uuid' => $vehicle->uuid])->delete();
    }
}
