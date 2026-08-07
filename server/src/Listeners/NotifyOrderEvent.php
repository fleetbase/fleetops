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
                $this->notify(OrderCanceled::class, $order, $reason, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderCompleted) {
                $this->notify(OrderCompleted::class, $order, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderFailed) {
                $reason = $event->activity ? $event->activity->get('details') : '';
                $this->notify(OrderFailed::class, $order, $reason, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDispatchFailed) {
                $this->notify(OrderDispatchFailed::class, $order);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDispatched) {
                $this->notify(OrderDispatched::class, $order, $event->waypoint);
            }

            if ($event instanceof \Fleetbase\FleetOps\Events\OrderDriverAssigned) {
                $this->notify(OrderAssigned::class, $order);
            }
        }
    }

    protected function notify(string $notificationClass, mixed ...$arguments): void
    {
        NotificationRegistry::notify($notificationClass, ...$arguments);
    }
}
