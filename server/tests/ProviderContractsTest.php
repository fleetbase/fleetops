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
use Fleetbase\FleetOps\Notifications\OrderDispatched as OrderDispatchedNotification;
use Fleetbase\FleetOps\Notifications\OrderDispatchFailed;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\FleetOps\Observers\DriverObserver;
use Fleetbase\FleetOps\Observers\FleetObserver;
use Fleetbase\FleetOps\Observers\OrderObserver;
use Fleetbase\FleetOps\Observers\PayloadObserver;
use Fleetbase\FleetOps\Observers\VehicleObserver;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Fleetbase\FleetOps\Providers\FleetOpsServiceProvider;
use Fleetbase\FleetOps\Providers\NotificationServiceProvider;
use Fleetbase\FleetOps\Providers\ReportSchemaServiceProvider;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\FleetOps\Tracking\TrackingProviderRegistry;
use Fleetbase\Listeners\SendResourceLifecycleWebhook;
use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Support\Reporting\ReportSchemaRegistry;

function providerDefaultProperty(string $class, string $property): mixed
{
    return (new ReflectionClass($class))->getDefaultProperties()[$property];
}

class FleetOpsProviderContractsAppFake
{
    public array $registeredProviders     = [];
    public array $singletons              = [];
    public array $resolvingCallbacks      = [];
    public array $afterResolvingCallbacks = [];

    public function register(string $provider): void
    {
        $this->registeredProviders[] = $provider;
    }

    public function singleton(string $abstract, callable $callback): void
    {
        $this->singletons[$abstract] = $callback;
    }

    public function resolving(string $abstract, callable $callback): void
    {
        $this->resolvingCallbacks[$abstract][] = $callback;
    }

    public function afterResolving(string $abstract, callable $callback): void
    {
        $this->afterResolvingCallbacks[$abstract][] = $callback;
    }

    public function resolved(string $abstract): bool
    {
        return false;
    }

    public function make(string $abstract): never
    {
        throw new RuntimeException("Unexpected make call for {$abstract}");
    }
}

class FleetOpsProviderContractsScheduledEventFake
{
    public array $methods = [];

    public function __construct(public string $command)
    {
    }

    public function everyMinute(): self
    {
        $this->methods[] = ['everyMinute'];

        return $this;
    }

    public function everyTenMinutes(): self
    {
        $this->methods[] = ['everyTenMinutes'];

        return $this;
    }

    public function daily(): self
    {
        $this->methods[] = ['daily'];

        return $this;
    }

    public function withoutOverlapping(): self
    {
        $this->methods[] = ['withoutOverlapping'];

        return $this;
    }

    public function storeOutputInDb(): self
    {
        $this->methods[] = ['storeOutputInDb'];

        return $this;
    }
}

class FleetOpsProviderContractsScheduleFake
{
    public array $commands = [];

    public function command(string $command): FleetOpsProviderContractsScheduledEventFake
    {
        $event                    = new FleetOpsProviderContractsScheduledEventFake($command);
        $this->commands[$command] = $event;

        return $event;
    }
}

class FleetOpsProviderContractsProviderProbe extends FleetOpsServiceProvider
{
    public array $calls                                     = [];
    public ?FleetOpsProviderContractsScheduleFake $schedule = null;

    public function registerObservers(): void
    {
        $this->calls[] = ['registerObservers'];
    }

    public function registerCommands(): void
    {
        $this->calls[] = ['registerCommands'];
    }

    public function scheduleCommands(?callable $callback = null): void
    {
        $this->calls[]  = ['scheduleCommands'];
        $this->schedule = new FleetOpsProviderContractsScheduleFake();
        $callback($this->schedule);
    }

    public function registerExpansionsFrom($from = null, $namespace = null): void
    {
        $this->calls[] = ['registerExpansionsFrom', $from, $namespace];
    }

    protected function loadRoutesFrom($path)
    {
        $this->calls[] = ['loadRoutesFrom', $path];
    }

    protected function loadMigrationsFrom($paths)
    {
        $this->calls[] = ['loadMigrationsFrom', $paths];
    }

    protected function loadViewsFrom($path, $namespace)
    {
        $this->calls[] = ['loadViewsFrom', $path, $namespace];
    }

    protected function mergeConfigFrom($path, $key)
    {
        $this->calls[] = ['mergeConfigFrom', $path, $key];
    }
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

test('fleetops service provider executes package registration and boot wiring', function () {
    $originalNotifications = NotificationRegistry::$notifications;
    $originalNotifiables   = NotificationRegistry::$notifiables;

    try {
        NotificationRegistry::$notifications = [];
        NotificationRegistry::$notifiables   = [];

        $app      = new FleetOpsProviderContractsAppFake();
        $provider = new FleetOpsProviderContractsProviderProbe($app);

        $provider->register();

        expect($app->registeredProviders)->toBe([
            CoreServiceProvider::class,
            ReportSchemaServiceProvider::class,
        ])
            ->and(array_keys($app->singletons))->toBe([
                GeofenceIntersectionService::class,
                OrchestrationEngineRegistry::class,
                TrackingProviderRegistry::class,
                FuelProviderRegistry::class,
            ])
            ->and(($app->singletons[GeofenceIntersectionService::class])())->toBeInstanceOf(GeofenceIntersectionService::class)
            ->and(($app->singletons[OrchestrationEngineRegistry::class])())->toBeInstanceOf(OrchestrationEngineRegistry::class)
            ->and(($app->singletons[TrackingProviderRegistry::class])())->toBeInstanceOf(TrackingProviderRegistry::class)
            ->and(($app->singletons[FuelProviderRegistry::class])())->toBeInstanceOf(FuelProviderRegistry::class);

        $provider->boot();

        $orchestrationRegistry = new OrchestrationEngineRegistry();
        foreach ($app->resolvingCallbacks[OrchestrationEngineRegistry::class] as $callback) {
            $callback($orchestrationRegistry);
        }

        $trackingRegistry = new TrackingProviderRegistry();
        foreach ($app->resolvingCallbacks[TrackingProviderRegistry::class] as $callback) {
            $callback($trackingRegistry);
        }

        expect($provider->calls)->toContain(
            ['registerObservers'],
            ['registerCommands'],
            ['scheduleCommands'],
            ['loadRoutesFrom', dirname(__DIR__) . '/src/Providers/../routes.php'],
            ['loadMigrationsFrom', dirname(__DIR__) . '/src/Providers/../../migrations'],
            ['loadViewsFrom', dirname(__DIR__) . '/src/Providers/../../resources/views', 'fleetops'],
            ['mergeConfigFrom', dirname(__DIR__) . '/src/Providers/../../config/fleetops.php', 'fleetops'],
            ['mergeConfigFrom', dirname(__DIR__) . '/src/Providers/../../config/geocoder.php', 'geocoder']
        )
            ->and($provider->schedule?->commands)->toHaveKeys([
                'fleetops:dispatch-orders',
                'fleetops:dispatch-adhoc',
                'fleetops:update-estimations',
                'fleetops:purge-service-quotes',
                'fleetops:process-maintenance-triggers',
                'fleetops:send-maintenance-reminders',
                'fleetops:process-operational-alerts',
                'fleetops:sync-telematics',
            ])
            ->and($provider->schedule?->commands['fleetops:dispatch-orders']->methods)->toContain(['everyMinute'], ['withoutOverlapping'], ['storeOutputInDb'])
            ->and($provider->schedule?->commands['fleetops:update-estimations']->methods)->toContain(['everyTenMinutes'], ['withoutOverlapping'])
            ->and($orchestrationRegistry->has('vroom'))->toBeTrue()
            ->and($orchestrationRegistry->has('greedy'))->toBeTrue()
            ->and($orchestrationRegistry->has('capacity'))->toBeTrue()
            ->and($trackingRegistry->has('google_routes'))->toBeTrue()
            ->and($trackingRegistry->has('osrm'))->toBeTrue()
            ->and($trackingRegistry->has('calculated'))->toBeTrue()
            ->and(collect(NotificationRegistry::$notifications)->pluck('definition')->all())->toContain(
                OrderAssigned::class,
                OrderDispatchFailed::class,
                LateDeparture::class,
                RouteDeviation::class,
                ProlongedStoppage::class
            )
            ->and(NotificationRegistry::$notifiables)->toContain(
                Fleetbase\FleetOps\Models\Contact::class,
                Driver::class,
                Fleetbase\FleetOps\Models\Vendor::class,
                Fleet::class,
                'dynamic:customer',
                'dynamic:driver',
                'dynamic:facilitator'
            );
    } finally {
        NotificationRegistry::$notifications = $originalNotifications;
        NotificationRegistry::$notifiables   = $originalNotifiables;
    }
});

test('report schema service provider registers fleetops tables after registry resolution', function () {
    $app      = new FleetOpsProviderContractsAppFake();
    $provider = new ReportSchemaServiceProvider($app);

    $provider->register();

    $registry = new ReportSchemaRegistry();
    foreach ($app->afterResolvingCallbacks[ReportSchemaRegistry::class] as $callback) {
        $callback($registry);
    }

    expect($registry->getRegisteredTableNames())->toContain(
        'orders',
        'drivers',
        'vehicles',
        'places',
        'contacts',
        'vendors',
        'fuel_reports'
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

test('notification service provider registers fleetops notifications and notifiables', function () {
    $originalNotifications = NotificationRegistry::$notifications;
    $originalNotifiables   = NotificationRegistry::$notifiables;

    try {
        NotificationRegistry::$notifications = [];
        NotificationRegistry::$notifiables   = [];

        (new NotificationServiceProvider(app()))->boot();

        expect(collect(NotificationRegistry::$notifications)->pluck('definition')->all())->toBe([
            OrderAssigned::class,
            Fleetbase\FleetOps\Notifications\OrderCanceled::class,
            OrderDispatchedNotification::class,
            OrderDispatchFailed::class,
            OrderPing::class,
            LateDeparture::class,
            RouteDeviation::class,
            ProlongedStoppage::class,
        ])
            ->and(NotificationRegistry::$notifiables)->toBe([
                Fleetbase\FleetOps\Models\Contact::class,
                Driver::class,
                Fleetbase\FleetOps\Models\Vendor::class,
                Fleet::class,
                'dynamic:customer',
                'dynamic:driver',
                'dynamic:facilitator',
            ]);
    } finally {
        NotificationRegistry::$notifications = $originalNotifications;
        NotificationRegistry::$notifiables   = $originalNotifiables;
    }
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
