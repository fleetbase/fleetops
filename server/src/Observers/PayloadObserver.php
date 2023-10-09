<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Payload;

class PayloadObserver
{
    /**
     * Handle the Payload "creating" event.
     *
     * @param  \Fleetbase\FleetOps\Models\Payload  $payload
     * @return void
     */
    public function created(Payload $payload)
    {
        // load the order
        $payload->updateOrderDistanceAndTime();
    }
    /**
     * Handle the Payload "updating" event.
     *
     * @param  \Fleetbase\FleetOps\Models\Payload  $payload
     * @return void
     */
    public function updating(Payload $payload)
    {
        $waypoints = request()->array('payload.waypoints');
        $payload->updateWaypoints($waypoints);
    }
}
