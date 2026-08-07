<?php

use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Illuminate\Support\Str;

if (!Str::hasMacro('humanize')) {
    Str::macro('humanize', fn ($value) => str_replace('_', ' ', Str::snake((string) $value)));
}

class FleetOpsConcreteOrderDispatchedEvent extends OrderDispatched
{
    public function __construct(private Order $order)
    {
    }

    public function getModelRecord(): Order
    {
        return $this->order;
    }
}

class FleetOpsConcreteOrderForDispatch extends Order
{
    public bool $hasDriverAssigned       = true;
    public bool $adhoc                   = false;
    public ?string $company_uuid         = 'company-uuid';
    public ?string $tracking_number_uuid = 'tracking-number-uuid';
    public ?string $driver_assigned_uuid = 'driver-uuid';
    public mixed $dispatched             = false;
    public mixed $dispatched_at          = null;
    public mixed $pickupLocation         = null;
    public ?OrderConfig $dispatchConfig  = null;
    public int $adhocDistance            = 1500;
    public array $calls                  = [];

    public function config(): ?OrderConfig
    {
        return $this->dispatchConfig;
    }

    public function save(array $options = []): bool
    {
        $this->calls[] = ['save', $options];

        return true;
    }

    public function flushAttributesCache(): bool
    {
        $this->calls[] = ['flushAttributesCache'];

        return true;
    }

    public function load($relations)
    {
        $this->calls[] = ['load', $relations];

        return $this;
    }

    public function getPickupLocation()
    {
        return $this->pickupLocation;
    }

    public function getAdhocDistance()
    {
        return $this->adhocDistance;
    }
}

class FleetOpsConcreteOrderConfigForDispatch extends OrderConfig
{
    public ?Activity $dispatchActivity = null;

    public function getDispatchActivity(): ?Activity
    {
        return $this->dispatchActivity;
    }
}

class FleetOpsConcreteDriverForDispatch extends Driver
{
    public float $distance      = 0;
    public array $notifications = [];

    public function notify($instance)
    {
        $this->notifications[] = $instance;
    }
}

class FleetOpsConcreteHandleOrderDispatchedProbe extends HandleOrderDispatched
{
    public ?Driver $assignedDriver         = null;
    public bool $throwAssignedNotification = false;
    public bool $throwAdhocNotification    = false;
    public array $assignedNotifications    = [];
    public array $adhocNotifications       = [];

    public function getDispatchActivityForTest(Order $order): mixed
    {
        return $this->getDispatchActivity($order);
    }

    protected function doesntHaveDispatchActivity($order): bool
    {
        return false;
    }

    protected function findAssignedDriver($order): ?Driver
    {
        return $this->assignedDriver;
    }

    protected function nearbyAvailableDrivers($pickup, int|float $distance)
    {
        return collect([
            new FleetOpsConcreteDriverForDispatch(),
        ]);
    }

    protected function notifyAssignedDriver(Driver $driver, $order): void
    {
        if ($this->throwAssignedNotification) {
            throw new RuntimeException('assigned notification failed');
        }

        $this->assignedNotifications[] = $driver;
        parent::notifyAssignedDriver($driver, $order);
    }

    protected function notifyAdhocDriver(Driver $driver, $order): void
    {
        if ($this->throwAdhocNotification) {
            throw new RuntimeException('adhoc notification failed');
        }

        $this->adhocNotifications[] = $driver;
        parent::notifyAdhocDriver($driver, $order);
    }
}

function fleetopsConcreteOrderForDispatch(array $attributes = []): FleetOpsConcreteOrderForDispatch
{
    $order = new FleetOpsConcreteOrderForDispatch();
    $order->setRawAttributes(array_merge([
        'uuid'      => 'order-uuid',
        'public_id' => 'order_public',
        'tracking'  => 'TRK-123',
    ], $attributes), true);
    $order->exists = true;

    return $order;
}

test('order dispatched concrete dispatch activity helper returns nullable activity', function () {
    $listener = new FleetOpsConcreteHandleOrderDispatchedProbe();
    $order    = fleetopsConcreteOrderForDispatch();
    $activity = new Activity(['code' => 'DISPATCHED']);

    expect($listener->getDispatchActivityForTest($order))->toBeNull();

    $config                   = new FleetOpsConcreteOrderConfigForDispatch();
    $config->dispatchActivity = $activity;
    $order->dispatchConfig    = $config;

    expect($listener->getDispatchActivityForTest($order))->toBe($activity);
});

test('order dispatched listener swallows assigned driver notification failures', function () {
    $listener = new FleetOpsConcreteHandleOrderDispatchedProbe();
    $order    = fleetopsConcreteOrderForDispatch();

    $listener->assignedDriver            = new FleetOpsConcreteDriverForDispatch();
    $listener->throwAssignedNotification = true;

    $listener->handle(new FleetOpsConcreteOrderDispatchedEvent($order));

    expect($order->dispatched)->toBeTrue()
        ->and($listener->assignedNotifications)->toBe([]);
});

test('order dispatched listener swallows adhoc driver notification failures', function () {
    $listener                 = new FleetOpsConcreteHandleOrderDispatchedProbe();
    $order                    = fleetopsConcreteOrderForDispatch();
    $order->adhoc             = true;
    $order->hasDriverAssigned = false;
    $order->pickupLocation    = new Fleetbase\LaravelMysqlSpatial\Types\Point(1.30, 103.80);

    $listener->throwAdhocNotification = true;

    $listener->handle(new FleetOpsConcreteOrderDispatchedEvent($order));

    expect($order->dispatched)->toBeTrue()
        ->and($listener->adhocNotifications)->toBe([]);
});
