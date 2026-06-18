<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Support\LiveCacheService;

class FleetObserver
{
    /**
     * Handle the Fleet "created" event.
     *
     * @return void
     */
    public function created(Fleet $fleet)
    {
        LiveCacheService::invalidate('operations-monitor');
    }

    /**
     * Handle the Fleet "updated" event.
     *
     * @return void
     */
    public function updated(Fleet $fleet)
    {
        LiveCacheService::invalidate('operations-monitor');
    }

    /**
     * Handle the Driver "deleted" event.
     *
     * @return void
     */
    public function deleted(Fleet $fleet)
    {
        // If the fleet being deleted is set as parent fleet, remove it as the parent fleet
        $subFleets = Fleet::where(['parent_fleet_uuid' => $fleet->uuid])->update(['parent_fleet_uuid' => null]);

        LiveCacheService::invalidate('operations-monitor');
    }
}
