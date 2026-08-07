<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed as OrderDispatchFailedNotification;
use Fleetbase\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class HandleOrderDispatchFailed implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDispatchFailed $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order = $event->getModelRecord();

        /** @var User */
        $createdBy = $this->findUser($order->created_by_uuid);

        // notify driver assigned order was canceled
        if ($createdBy) {
            $this->notifyUser($createdBy, $order, $event);
        }
    }

    protected function findUser(?string $uuid): ?User
    {
        return User::where('uuid', $uuid)->first();
    }

    protected function notifyUser(User $user, $order, OrderDispatchFailed $event): void
    {
        $user->notify(new OrderDispatchFailedNotification($order, $event));
    }
}
