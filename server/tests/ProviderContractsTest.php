<?php

use Fleetbase\Events\ScheduleItemCreated;
use Fleetbase\Events\ScheduleItemUpdated;
use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\FleetOps\Console\Commands\DispatchOrders;
use Fleetbase\FleetOps\Console\Commands\ProcessOperationalAlerts;
use Fleetbase\FleetOps\Console\Commands\SyncTelematics;
use Fleetbase\FleetOps\Events\GeofenceDwelled;
use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Events\OrderCompleted;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleDeliveryCompletion;
use Fleetbase\FleetOps\Listeners\HandleGeofenceDwelled;
use Fleetbase\FleetOps\Listeners\HandleGeofenceEntered;
use Fleetbase\FleetOps\Listeners\HandleGeofenceExited;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Listeners\HandleUserRemovedFromCompany;
use Fleetbase\FleetOps\Listeners\NotifyDriverOnShiftChange;
use Fleetbase\FleetOps\Listeners\NotifyOrderEvent;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\OrderAssigned;
use Fleetbase\FleetOps\Notifications\OrderCompleted as OrderCompletedNotification;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\FleetOps\Observers\DriverObserver;
use Fleetbase\FleetOps\Observers\FleetObserver;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\FleetOps\Observers\PayloadObserver;
use Fleetbase\FleetOps\Observers\VehicleObserver;
use Fleetbase\FleetOps\Providers\EventServiceProvider;
use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;

function providerDefaultProperty(string $class, string $property): mixed
{
    return (new ReflectionClass($class))->getDefaultProperties()[$property];
}

test('fleetops service provider declares core observers and commands', function () {
    $observers = providerDefaultProperty(FleetOpsServiceProvider::class, 'observers');
    $commands  = providerDefaultProperty(FleetOpsServiceProvider::class, 'commands');

    expect($observers)->toMatchArray([
        Order::class   => OrderObserver::class,
        Payload::class => PayloadObserver::class,
        Driver::class  => DriverObserver::class,
        Vehicle::class => VehicleObserver::class,
        Fleet::class   => FleetObserver::class,
    ])
        ->and($commands)->toContain(
            DispatchOrders::class,
            ProcessOperationalAlerts::class,
            SyncTelematics::class
        );
});

test('fleetops service provider notification registry list includes operational alerts', function () {
    $method = new ReflectionMethod(FleetOpsServiceProvider::class, 'registerNotifications');
    $source = file($method->getFileName());
    $body   = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect($body)->toContain(
        OrderAssigned::class,
        OrderCompletedNotification::class,
        OrderPing::class,
        LateDeparture::class,
        RouteDeviation::class,
        ProlongedStoppage::class
    )
        ->and($body)->toContain(
            Driver::class,
            Fleet::class,
            'dynamic:customer',
            'dynamic:driver',
            'dynamic:facilitator'
        );
});

test('event service provider maps order geofence and schedule listeners', function () {
    $source = file_get_contents(dirname(__DIR__) . '/src/Providers/EventServiceProvider.php');

    expect($source)->toContain(
        OrderDispatched::class,
        HandleOrderDispatched::class,
        OrderCompleted::class,
        HandleDeliveryCompletion::class,
        GeofenceEntered::class,
        HandleGeofenceEntered::class,
        GeofenceExited::class,
        HandleGeofenceExited::class,
        GeofenceDwelled::class,
        HandleGeofenceDwelled::class,
        UserRemovedFromCompany::class,
        HandleUserRemovedFromCompany::class,
        ScheduleItemCreated::class,
        ScheduleItemUpdated::class,
        NotifyDriverOnShiftChange::class,
        SendResourceLifecycleWebhook::class,
        NotifyOrderEvent::class
    );
});
