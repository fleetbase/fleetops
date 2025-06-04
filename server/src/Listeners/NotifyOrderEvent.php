<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCanceled;
use Fleetbase\FleetOps\Notifications\OrderCompleted;
use Fleetbase\FleetOps\Notifications\OrderDispatched;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderFailed;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyOrderEvent implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle($event)
    {
        // Get the order record from the event
        $order = $event->getModelRecord();

        if ($order) {
            // Send a notification for order events
            if ($event instanceof \Fleetbase\FleetOps\Events\OrderCanceled) {
                $reason = $event->activity ? $event->activity->get('details') : '';
                NotificationRegistry::notify(OrderCanceled::class, $order, $reason, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderCompleted) {
                NotificationRegistry::notify(OrderCompleted::class, $order, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderFailed) {
                $reason = $event->activity ? $event->activity->get('details') : '';
                NotificationRegistry::notify(OrderFailed::class, $order, $reason, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDispatchFailed) {
                NotificationRegistry::notify(OrderDispatchFailed::class, $order);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDispatched) {
                NotificationRegistry::notify(OrderDispatched::class, $order, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDriverAssigned) {
                NotificationRegistry::notify(OrderAssigned::class, $order);
            }
        }
    }
}
