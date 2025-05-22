<?php

namespace Fleetbase\FleetOps\Flow;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Support\Str;

class Event
{
    /**
     * The name of the event.
     */
    public string $name;

    /**
     * The order associated with the event, if any.
     */
    public ?Order $order = null;

    /**
     * The waypoint associated with the event, if any.
     */
    public ?Waypoint $waypoint = null;

    /**
     * The activity for the order event.
     */
    public ?Activity $activity = null;

    /**
     * Constructs a new Event instance.
     *
     * @param string $name  the name of the event
     * @param ?Order $order the associated order, if any
     */
    public function __construct(string $name, ?Order $order = null, ?Activity $activity = null, ?Waypoint $waypoint = null)
    {
        $this->name     = $name;
        $this->order    = $order;
        $this->activity = $activity;
        $this->waypoint = $waypoint;
    }

    /**
     * Sets the order for the event.
     *
     * @param Order $order the order to associate with this event
     */
    public function setOrder(Order $order)
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Sets the activity for the order event.
     *
     * @param Activity $activity the activity which triggered this event
     */
    public function setActivity(Activity $activity)
    {
        $this->activity = $activity;

        return $this;
    }

    /**
     * Sets the waypoint for the order event.
     *
     * @param Waypoint $waypoint the waypoint assosciated to this event
     */
    public function setWaypoint(Waypoint $waypoint)
    {
        $this->waypoint = $waypoint;

        return $this;
    }

    /**
     * Triggers the event, optionally setting the order if not already set.
     *
     * Resolves the event class based on the event name and fires it using Laravel's event system.
     * If the order is not set in the event, and an Order object is provided, it sets the order before firing.
     *
     * @param ?Order    $order    optional order to associate with the event
     * @param ?Activity $activity optional activity which triggered the event
     */
    public function fire(?Order $order = null, ?Activity $activity = null, ?Waypoint $waypoint = null)
    {
        if (!$this->order && $order instanceof Order) {
            $this->setOrder($order);
        }

        if ($activity) {
            $this->setActivity($activity);
        }

        if ($waypoint) {
            $this->setWaypoint($waypoint);
        }

        $eventClass = $this->resolve();
        if ($eventClass) {
            $event           = new $eventClass($this->order);
            $event->activity = $activity;
            $event->waypoint = $waypoint;
            event($event);
        }
    }

    /**
     * Resolves the class name of the event based on the event name.
     *
     * Tries to locate the class in predefined namespaces and returns the fully qualified name if found.
     *
     * @return string|null the resolved class name or null if not found
     */
    public function resolve()
    {
        $eventClassName = Str::studly(preg_replace('/[^a-zA-Z0-9]/', ' ', $this->name));
        $eventClasses   = [
            '\\Fleetbase\\FleetOps\Events\\' . $eventClassName,
            '\\Fleetbase\\Events\\' . $eventClassName,
        ];

        foreach ($eventClasses as $eventClass) {
            if (Utils::classExists($eventClass)) {
                return $eventClass;
            }
        }

        return null;
    }
}
