<?php

namespace Fleetbase\FleetOps\Listeners;

use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Notifications\OrderDispatched as OrderDispatchedNotification;
use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

class HandleOrderDispatched implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(OrderDispatched $event)
    {
        /** @var Order $order */
        $order = $event->getModelRecord();

        // set company session
        session([
            'company' => $order->company_uuid,
        ]);

        /* make sure driver is assigned if not trigger failed dispatch */
        if (!$order->hasDriverAssigned && !$order->adhoc) {
            return $this->dispatchFailed($order, 'No driver assigned for order to dispatch to.');
        }

        /**
         * Check if dispatch activity already exists, if not -
         * Check for dispatch code in activity options, if there is the correct dispatch code update activity.
         **/
        $doesntHaveDispatchActivity = $this->doesntHaveDispatchActivity($order);
        if ($doesntHaveDispatchActivity) {
            $activity = $this->getDispatchActivity($order);
            if ($activity) {
                /** update order activity */
                $location = $order->getLastLocation();
                $order->setStatus($activity->code);
                $order->createActivity($activity, $location);
            }
        }

        /* update dispatch attributes */
        $order->dispatched    = true;
        $order->dispatched_at = Carbon::now();
        $order->save();
        $order->flushAttributesCache();

        // An adhoc order is offered to drivers by its dispatch strategy.
        if ($order->adhoc) {
            return $this->dispatchAdhoc($order);
        }

        /** @var \Fleetbase\Models\Driver */
        $driver = $this->findAssignedDriver($order);

        /* notify driver order has dispatched */
        if (!$driver) {
            return $this->dispatchFailed($order, 'Order was dispatched, but driver was unable to be notified.');
        }

        try {
            $this->notifyAssignedDriver($driver, $order);
        } catch (\Exception $exception) {
            // silently fail notifying driver for now
        }
    }

    protected function dispatchAdhoc($order): mixed
    {
        AdhocDispatch::for($order)->dispatch($order);

        return null;
    }

    protected function dispatchFailed($order, string $reason): mixed
    {
        return event(new OrderDispatchFailed($order, $reason));
    }

    protected function doesntHaveDispatchActivity($order): bool
    {
        return TrackingStatus::where(['code' => 'DISPATCHED', 'tracking_number_uuid' => $order->tracking_number_uuid])->doesntExist();
    }

    protected function getDispatchActivity($order): mixed
    {
        return $order->config()?->getDispatchActivity();
    }

    protected function findAssignedDriver($order): ?Driver
    {
        return Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();
    }

    protected function notifyAssignedDriver(Driver $driver, $order): void
    {
        $driver->notify(new OrderDispatchedNotification($order));
    }
}
