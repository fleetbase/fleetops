<?php

namespace Fleetbase\FleetOps\Events;

use Fleetbase\Events\ResourceLifecycleEvent;
use Fleetbase\FleetOps\Flow\Activity;

class OrderFailed extends ResourceLifecycleEvent
{
    /**
     * The event name.
     *
     * @var string
     */
    public $eventName = 'failed';

    /**
     * Assosciated activity which triggered the event.
     */
    public ?Activity $activity;
}
