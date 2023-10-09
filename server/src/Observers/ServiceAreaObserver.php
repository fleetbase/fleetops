<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Support\Utils as FleetOpsUtils;
use Fleetbase\FleetOps\Support\Utils;

class ServiceAreaObserver
{
    /**
     * Handle the ServiceArea "creating" event.
     *
     * @param  \Fleetbase\FleetOps\Models\ServiceArea  $serviceArea
     * @return void
     */
    public function creating(ServiceArea $serviceArea)
    {
        // if no border is set but country is, create the border from the country
        if (empty($serviceArea->border) && isset($serviceArea->country)) {
            $serviceArea->border = FleetOpsUtils::createPolygonFromCountry($serviceArea->country);
        }
    }

    /**
     * Handle the ServiceArea "deleted" event.
     *
     * @param  \Fleetbase\FleetOps\Models\ServiceArea  $serviceArea
     * @return void
     */
    public function deleted(ServiceArea $serviceArea)
    {
        $serviceArea->load(['zones']);

        Utils::deleteModels($serviceArea->zones);
    }
}
