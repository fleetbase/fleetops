<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Flow\Activity;

class OrderCompleted extends ResourceLifecycleEvent
{
    /**
     * The event name.
     *
     * @var string
     */
    public $eventName = 'completed';

    /**
     * Assosciated activity which triggered the event.
     */
    public ?Activity $activity;
}
