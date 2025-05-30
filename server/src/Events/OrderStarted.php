<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Flow\Activity;

class OrderStarted extends ResourceLifecycleEvent
{
    /**
     * The event name.
     *
     * @var string
     */
    public $eventName = 'started';

    /**
     * Assosciated activity which triggered the event.
     */
    public ?Activity $activity = null;
}
