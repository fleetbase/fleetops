<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Flow\Activity;

class OrderDriverAssigned extends ResourceLifecycleEvent
{
    /**
     * The event name.
     *
     * @var string
     */
    public $eventName = 'driver_assigned';

    /**
     * Assosciated activity which triggered the event.
     */
    public ?Activity $activity = null;
}
