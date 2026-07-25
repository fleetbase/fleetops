<?php

use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Listeners\HandleOrderDispatched;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

class FleetOpsOrderDispatchedEventFake extends OrderDispatched
{
    public function __construct(private Order $order)
    {
    }

    public function getModelRecord(): ?Order
    {
        return $this->order;
    }
}

class FleetOpsOrderDispatchedOrderFake extends Order
{
    public bool $hasDriverAssigned       = true;
    public bool $adhoc                   = false;
    public ?string $company_uuid         = 'company-uuid';
    public ?string $tracking_number_uuid = 'tracking-number-uuid';
    public ?string $driver_assigned_uuid = 'driver-uuid';
    public mixed $dispatched             = false;
    public mixed $dispatched_at          = null;
    public array $calls                  = [];
    public mixed $lastLocation           = 'last-location';
    public mixed $pickupLocation         = null;
    public int $adhocDistance            = 1500;
    public mixed $dispatchActivity       = null;

    public function getLastLocation()
    {
        $this->calls[] = ['getLastLocation'];

        return $this->lastLocation;
    }

    public function setStatus(?string $status, $andSave = true)
    {
        $this->calls[] = ['setStatus', $status, $andSave];

        return $this;
    }

    public function createActivity(Activity $activity, $location = [], $proof = null): TrackingStatus
    {
        $this->calls[] = ['createActivity', $activity->code, $location];

        return new TrackingStatus();
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
        $this->calls[] = ['getPickupLocation'];

        return $this->pickupLocation;
    }

    public function getAdhocDistance()
    {
        $this->calls[] = ['getAdhocDistance'];

        return $this->adhocDistance;
    }
}

class FleetOpsOrderDispatchedDriverFake extends Driver
{
    public ?string $public_id = null;
    public float $distance    = 0.0;
}

class FleetOpsHandleOrderDispatchedProbe extends HandleOrderDispatched
{
    public bool $doesntHaveDispatchActivity = true;
    public ?Driver $assignedDriver          = null;
    public Illuminate\Support\Collection $nearbyDrivers;
    public array $failures              = [];
    public array $assignedNotifications = [];
    public array $adhocNotifications    = [];
    public array $nearbyQueries         = [];

    public function __construct()
    {
        $this->nearbyDrivers = collect();
    }

    protected function dispatchFailed($order, string $reason): mixed
    {
        $this->failures[] = [$order->driver_assigned_uuid, $reason];

        return null;
    }

    protected function doesntHaveDispatchActivity($order): bool
    {
        return $this->doesntHaveDispatchActivity;
    }

    protected function getDispatchActivity($order): mixed
    {
        $order->calls[] = ['getDispatchActivity'];

        return $order->dispatchActivity;
    }

    protected function nearbyAvailableDrivers($pickup, int|float $distance)
    {
        $this->nearbyQueries[] = [(string) $pickup, $distance];

        return $this->nearbyDrivers;
    }

    protected function notifyAdhocDriver(Driver $driver, $order): void
    {
        $this->adhocNotifications[] = [$driver->public_id, $driver->distance, $order->driver_assigned_uuid];
    }

    protected function findAssignedDriver($order): ?Driver
    {
        return $this->assignedDriver;
    }

    protected function notifyAssignedDriver(Driver $driver, $order): void
    {
        $this->assignedNotifications[] = [$driver->public_id, $order->driver_assigned_uuid];
    }
}

function fleetOpsDispatchListenerOrder(array $attributes = []): FleetOpsOrderDispatchedOrderFake
{
    $order = new FleetOpsOrderDispatchedOrderFake();

    foreach ($attributes as $key => $value) {
        $order->{$key} = $value;
    }

    return $order;
}

function fleetOpsDispatchListenerDriver(string $publicId, float $distance = 0): FleetOpsOrderDispatchedDriverFake
{
    $driver            = new FleetOpsOrderDispatchedDriverFake();
    $driver->public_id = $publicId;
    $driver->distance  = $distance;

    return $driver;
}

test('order dispatched listener emits failure when a non adhoc order has no assigned driver', function () {
    $listener = new FleetOpsHandleOrderDispatchedProbe();
    $order    = fleetOpsDispatchListenerOrder([
        'hasDriverAssigned' => false,
        'adhoc'             => false,
    ]);

    $listener->handle(new FleetOpsOrderDispatchedEventFake($order));

    expect($listener->failures)->toBe([
        ['driver-uuid', 'No driver assigned for order to dispatch to.'],
    ])
        ->and($order->calls)->toBe([]);
});

test('order dispatched listener records dispatch activity and notifies assigned driver', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00'));

    $listener                 = new FleetOpsHandleOrderDispatchedProbe();
    $listener->assignedDriver = fleetOpsDispatchListenerDriver('assigned_driver');
    $order                    = fleetOpsDispatchListenerOrder([
        'dispatchActivity' => new Activity(['code' => 'DISPATCHED']),
    ]);

    $listener->handle(new FleetOpsOrderDispatchedEventFake($order));

    expect(session('company'))->toBe('company-uuid')
        ->and($order->dispatched)->toBeTrue()
        ->and($order->dispatched_at->toDateTimeString())->toBe('2026-07-26 12:00:00')
        ->and($order->calls)->toContain(['getDispatchActivity'])
        ->and($order->calls)->toContain(['getLastLocation'])
        ->and($order->calls)->toContain(['setStatus', 'DISPATCHED', true])
        ->and($order->calls)->toContain(['createActivity', 'DISPATCHED', 'last-location'])
        ->and($order->calls)->toContain(['save', []])
        ->and($order->calls)->toContain(['flushAttributesCache'])
        ->and($listener->assignedNotifications)->toBe([
            ['assigned_driver', 'driver-uuid'],
        ])
        ->and($listener->failures)->toBe([]);

    Carbon::setTestNow();
});

test('order dispatched listener skips duplicate dispatch activity and fails when assigned driver cannot be resolved', function () {
    $listener                             = new FleetOpsHandleOrderDispatchedProbe();
    $listener->doesntHaveDispatchActivity = false;
    $order                                = fleetOpsDispatchListenerOrder();

    $listener->handle(new FleetOpsOrderDispatchedEventFake($order));

    expect($order->calls)->not->toContain(['getDispatchActivity'])
        ->and($listener->assignedNotifications)->toBe([])
        ->and($listener->failures)->toBe([
            ['driver-uuid', 'Order was dispatched, but driver was unable to be notified.'],
        ]);
});

test('order dispatched listener pings nearby adhoc drivers and ignores invalid pickup points', function () {
    $listener                = new FleetOpsHandleOrderDispatchedProbe();
    $listener->nearbyDrivers = collect([
        fleetOpsDispatchListenerDriver('nearby_one', 100.5),
        fleetOpsDispatchListenerDriver('nearby_two', 250.25),
    ]);
    $order                   = fleetOpsDispatchListenerOrder([
        'adhoc'             => true,
        'hasDriverAssigned' => false,
        'pickupLocation'    => new Point(1.30, 103.80),
        'adhocDistance'     => 2400,
    ]);

    $listener->handle(new FleetOpsOrderDispatchedEventFake($order));

    expect($order->calls)->toContain(['load', ['company']])
        ->and($order->calls)->toContain(['getPickupLocation'])
        ->and($order->calls)->toContain(['getAdhocDistance'])
        ->and($listener->nearbyQueries)->toBe([[(string) $order->pickupLocation, 2400]])
        ->and($listener->adhocNotifications)->toBe([
            ['nearby_one', 100.5, 'driver-uuid'],
            ['nearby_two', 250.25, 'driver-uuid'],
        ])
        ->and($listener->assignedNotifications)->toBe([]);

    $invalidPickup        = fleetOpsDispatchListenerOrder([
        'adhoc'             => true,
        'hasDriverAssigned' => false,
        'pickupLocation'    => null,
    ]);
    $invalidPickupListener = new FleetOpsHandleOrderDispatchedProbe();

    $invalidPickupListener->handle(new FleetOpsOrderDispatchedEventFake($invalidPickup));

    expect($invalidPickupListener->nearbyQueries)->toBe([])
        ->and($invalidPickupListener->adhocNotifications)->toBe([]);
});
