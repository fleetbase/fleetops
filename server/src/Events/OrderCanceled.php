<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Models\Waypoint;

class OrderCanceled extends ResourceLifecycleEvent
{
    /**
     * The event name.
     *
     * @var string
     */
    public $eventName = 'canceled';

    /**
     * Assosciated activity which triggered the event.
     */
    public ?Activity $activity = null;

    /**
     * Assosciated order waypoint which event is for.
     */
    public ?Waypoint $waypoint = null;
}
