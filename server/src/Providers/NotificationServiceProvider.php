<?php

namespace Fleetbase\FleetOps\Providers;

use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Providers\CoreServiceProvider;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('FleetOps cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * FleetOps service provider.
 */
class NotificationServiceProvider extends CoreServiceProvider
{
    /**
     * Register any application services.
     *
     * Within the register method, you should only bind things into the
     * service container. You should never attempt to register any event
     * listeners, routes, or any other piece of functionality within the
     * register method.
     *
     * More information on this can be found in the Laravel documentation:
     * https://laravel.com/docs/8.x/providers
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(FleetOpsServiceProvider::class);
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     *
     * @throws \Exception if the `fleetbase/core-api` package is not installed
     */
    public function boot()
    {
        // Register Notifications
        NotificationRegistry::register([
            \Fleetbase\FleetOps\Notifications\OrderAssigned::class,
            \Fleetbase\FleetOps\Notifications\OrderCanceled::class,
            \Fleetbase\FleetOps\Notifications\OrderDispatched::class,
            \Fleetbase\FleetOps\Notifications\OrderDispatchFailed::class,
            \Fleetbase\FleetOps\Notifications\OrderPing::class,
        ]);

        // Register Notifiables
        NotificationRegistry::registerNotifiable([
            \Fleetbase\FleetOps\Models\Contact::class,
            \Fleetbase\FleetOps\Models\Driver::class,
            \Fleetbase\FleetOps\Models\Vendor::class,
            \Fleetbase\FleetOps\Models\Fleet::class,
        ]);
    }
}
