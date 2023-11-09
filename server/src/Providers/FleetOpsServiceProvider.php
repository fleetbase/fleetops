<?php

namespace Fleetbase\FleetOps\Providers;

use Brick\Geo\Engine\GeometryEngineRegistry;
use Brick\Geo\Engine\GEOSEngine;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\NotificationRegistry;

if (!class_exists(CoreServiceProvider::class)) {
    throw new \Exception('FleetOps cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * FleetOps service provider.
 */
class FleetOpsServiceProvider extends CoreServiceProvider
{
    /**
     * The observers registered with the service provider.
     *
     * @var array
     */
    public $observers = [
        \Fleetbase\FleetOps\Models\Order::class          => \Fleetbase\FleetOps\Observers\OrderObserver::class,
        \Fleetbase\FleetOps\Models\Payload::class        => \Fleetbase\FleetOps\Observers\PayloadObserver::class,
        \Fleetbase\FleetOps\Models\Place::class          => \Fleetbase\FleetOps\Observers\PlaceObserver::class,
        \Fleetbase\FleetOps\Models\ServiceRate::class    => \Fleetbase\FleetOps\Observers\ServiceRateObserver::class,
        \Fleetbase\FleetOps\Models\PurchaseRate::class   => \Fleetbase\FleetOps\Observers\PurchaseRateObserver::class,
        \Fleetbase\FleetOps\Models\ServiceArea::class    => \Fleetbase\FleetOps\Observers\ServiceAreaObserver::class,
        \Fleetbase\FleetOps\Models\TrackingNumber::class => \Fleetbase\FleetOps\Observers\TrackingNumberObserver::class,
        \Fleetbase\FleetOps\Models\Driver::class         => \Fleetbase\FleetOps\Observers\DriverObserver::class,
        \Fleetbase\FleetOps\Models\Vehicle::class        => \Fleetbase\FleetOps\Observers\VehicleObserver::class,
        \Fleetbase\FleetOps\Models\Fleet::class          => \Fleetbase\FleetOps\Observers\FleetObserver::class,
        \Fleetbase\Models\User::class                    => \Fleetbase\FleetOps\Observers\UserObserver::class,
    ];

    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Fleetbase\FleetOps\Console\Commands\DispatchAdhocOrders::class,
        \Fleetbase\FleetOps\Console\Commands\DispatchOrders::class,
        \Fleetbase\FleetOps\Console\Commands\TrackOrderDistanceAndTime::class,
    ];

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
        $this->app->register(CoreServiceProvider::class);
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
        $this->registerObservers();
        $this->registerCommands();
        $this->scheduleCommands(function ($schedule) {
            $schedule->command('fleetops:dispatch-orders')->everyMinute();
            $schedule->command('fleetops:dispatch-adhoc')->everyMinute();
            $schedule->command('fleetops:update-estimations')->everyFifteenMinutes();
        });
        $this->registerNotifications();
        $this->registerExpansionsFrom(__DIR__ . '/../Expansions');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->loadMigrationsFrom(__DIR__ . '/../../migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'fleetops');
        $this->mergeConfigFrom(__DIR__ . '/../../config/fleetops.php', 'fleetops');
        $this->mergeConfigFrom(__DIR__ . '/../../config/api.php', 'api');
        $this->mergeConfigFrom(__DIR__ . '/../../config/cache.stores.php', 'cache.stores');
        $this->mergeConfigFrom(__DIR__ . '/../../config/geocoder.php', 'geocoder');
        $this->mergeConfigFrom(__DIR__ . '/../../config/dompdf.php', 'dompdf');

        // Register the GeometryEngine for GEOSEngine
        GeometryEngineRegistry::set(new GEOSEngine());
    }

    public function registerNotifications()
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
