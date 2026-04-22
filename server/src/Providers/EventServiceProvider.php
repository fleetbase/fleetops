<?php

namespace Fleetbase\FleetOps\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        /*
         * Order Events
         */
        \Fleetbase\FleetOps\Events\OrderCanceled::class       => [\Fleetbase\FleetOps\Listeners\HandleOrderCanceled::class, \Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class],
        \Fleetbase\FleetOps\Events\OrderDispatched::class     => [\Fleetbase\FleetOps\Listeners\HandleOrderDispatched::class, \Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class],
        \Fleetbase\FleetOps\Events\OrderDispatchFailed::class => [\Fleetbase\FleetOps\Listeners\HandleOrderDispatchFailed::class, \Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class],
        \Fleetbase\FleetOps\Events\OrderDriverAssigned::class => [\Fleetbase\FleetOps\Listeners\HandleOrderDriverAssigned::class, \Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class],
        \Fleetbase\FleetOps\Events\OrderCompleted::class      => [\Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class, \Fleetbase\FleetOps\Listeners\HandleDeliveryCompletion::class],
        \Fleetbase\FleetOps\Events\OrderFailed::class         => [\Fleetbase\Listeners\SendResourceLifecycleWebhook::class, \Fleetbase\FleetOps\Listeners\NotifyOrderEvent::class],
        \Fleetbase\FleetOps\Events\OrderReady::class          => [\Fleetbase\FleetOps\Listeners\HandleOrderReady::class],

        /*
         * Geofence Events
         *
         * Each event is handled by a domain listener (business logic, event log)
         * and the generic SendResourceLifecycleWebhook listener (webhook delivery).
         */
        \Fleetbase\FleetOps\Events\GeofenceEntered::class => [
            \Fleetbase\FleetOps\Listeners\HandleGeofenceEntered::class,
            \Fleetbase\Listeners\SendResourceLifecycleWebhook::class,
        ],
        \Fleetbase\FleetOps\Events\GeofenceExited::class  => [
            \Fleetbase\FleetOps\Listeners\HandleGeofenceExited::class,
            \Fleetbase\Listeners\SendResourceLifecycleWebhook::class,
        ],
        \Fleetbase\FleetOps\Events\GeofenceDwelled::class => [
            \Fleetbase\FleetOps\Listeners\HandleGeofenceDwelled::class,
            \Fleetbase\Listeners\SendResourceLifecycleWebhook::class,
        ],

        /*
         * Core Events
         */
        \Fleetbase\Events\UserRemovedFromCompany::class => [\Fleetbase\FleetOps\Listeners\HandleUserRemovedFromCompany::class],

        /*
         * Scheduling Events
         */
        \Fleetbase\Events\ScheduleItemCreated::class => [\Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange::class],
        \Fleetbase\Events\ScheduleItemUpdated::class => [\Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange::class],
    ];
}
