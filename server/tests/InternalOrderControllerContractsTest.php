<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Support\OrderTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsInternalOrderLifecycleControllerProbe extends OrderController
{
    public Collection $orders;
    public ?FleetOpsInternalOrderLifecycleOrderFake $order   = null;
    public ?FleetOpsInternalOrderLifecycleDriverFake $driver = null;
    public array $trackingStatusExists                       = [];
    public array $assignedOrderUuids                         = [];
    public ?string $assignedDriverUuid                       = null;
    public array $bulkNotification                           = [];
    public int $transactions                                 = 0;

    protected function ordersByUuid(array $ids)
    {
        $this->orders->each(fn ($order) => $order->setAttribute('queried_ids', $ids));

        return $this->orders;
    }

    protected function trackingStatusExists(?string $trackingNumberUuid, string $code): bool
    {
        return $this->trackingStatusExists[$trackingNumberUuid . ':' . $code] ?? false;
    }

    protected function findDriverByUuid(string $uuid): ?Driver
    {
        $this->driver?->setAttribute('lookup_uuid', $uuid);

        return $this->driver;
    }

    protected function driverDisplayName(Driver $driver): string
    {
        return 'Ada Driver';
    }

    protected function validateBulkAssignDriverRequest(Request $request): array
    {
        return [
            'ids'    => $request->input('ids', []),
            'driver' => $request->input('driver'),
        ];
    }

    protected function findOrderByUuid(string $uuid): ?Order
    {
        $this->order?->setAttribute('lookup_uuid', $uuid);

        return $this->order;
    }

    protected function findOrderById(string $id, array $with = []): ?Order
    {
        $this->order?->setAttribute('lookup_id', $id);
        $this->order?->setAttribute('lookup_with', $with);

        return $this->order;
    }

    protected function assignDriverToOrders($orderUuids, Driver $driver): void
    {
        $this->assignedOrderUuids = $orderUuids->all();
        $this->assignedDriverUuid = $driver->uuid;
    }

    protected function dispatchBulkAssignedDriverNotification(array $orderUuids, Driver $driver): void
    {
        $this->bulkNotification = [$orderUuids, $driver->uuid];
    }

    protected function runTransaction(callable $callback): mixed
    {
        $this->transactions++;

        return $callback();
    }
}

class FleetOpsInternalOrderLifecycleOrderFake extends Order
{
    public bool $canceledForTest                                      = false;
    public bool $dispatchedForTest                                    = false;
    public bool $dispatchedWithActivityForTest                        = false;
    public bool $hasDriverAssignedForTest                             = true;
    public bool $adhocForTest                                         = false;
    public bool $dispatchedAttributeForTest                           = false;
    public bool $hasOrderConfigForTest                                = true;
    public ?FleetOpsInternalOrderLifecycleTrackerFake $trackerForTest = null;

    public function cancel()
    {
        $this->canceledForTest = true;
        $this->forceFill(['status' => 'canceled']);

        return $this;
    }

    public function dispatch(bool $save = true): Order
    {
        $this->dispatchedForTest = true;
        $this->forceFill(['status' => 'dispatched']);

        return $this;
    }

    public function dispatchWithActivity(): Order
    {
        $this->dispatchedWithActivityForTest = true;
        $this->forceFill(['status' => 'dispatched']);

        return $this;
    }

    public function ensureOrderConfig(): ?OrderConfig
    {
        if (!$this->hasOrderConfigForTest) {
            return null;
        }

        $orderConfig = new OrderConfig();
        $orderConfig->setRawAttributes(['uuid' => 'order-config-uuid'], true);

        return $orderConfig;
    }

    public function getHasDriverAssignedAttribute(): bool
    {
        return $this->hasDriverAssignedForTest;
    }

    public function getAdhocAttribute(): bool
    {
        return $this->adhocForTest;
    }

    public function getDispatchedAttribute(): bool
    {
        return $this->dispatchedAttributeForTest;
    }

    public function tracker(): OrderTracker
    {
        return $this->trackerForTest ??= new FleetOpsInternalOrderLifecycleTrackerFake($this);
    }
}

class FleetOpsInternalOrderLifecycleDriverFake extends Driver
{
}

class FleetOpsInternalOrderLifecycleTrackerFake extends OrderTracker
{
    public array $toArrayOptions = [];
    public array $etaOptions     = [];

    public function toArray(array $options = []): array
    {
        $this->toArrayOptions = $options;

        return ['tracker' => 'info', 'options' => $options];
    }

    public function eta(array $options = []): array
    {
        $this->etaOptions = $options;

        return ['eta' => [['stop' => 'dropoff']], 'options' => $options];
    }
}

function fleetopsInternalOrderLifecycleOrder(string $uuid, string $status = 'created', ?string $trackingNumberUuid = null): FleetOpsInternalOrderLifecycleOrderFake
{
    $order = new FleetOpsInternalOrderLifecycleOrderFake();
    $order->setRawAttributes([
        'uuid'                 => $uuid,
        'status'               => $status,
        'tracking_number_uuid' => $trackingNumberUuid,
    ], true);

    return $order;
}

function fleetopsInternalOrderLifecycleController(array $orders = []): FleetOpsInternalOrderLifecycleControllerProbe
{
    $controller         = new FleetOpsInternalOrderLifecycleControllerProbe();
    $controller->orders = new Collection($orders);
    $controller->order  = $orders[0] ?? fleetopsInternalOrderLifecycleOrder('order-uuid');
    $controller->driver = new FleetOpsInternalOrderLifecycleDriverFake();
    $controller->driver->setRawAttributes([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Ada Driver',
    ], true);

    return $controller;
}

function fleetopsBulkActionRequest(array $payload): Request
{
    return Request::create('/internal/v1/orders/bulk', 'POST', $payload);
}

function fleetopsBulkDispatchRequest(array $payload): BulkDispatchRequest
{
    return BulkDispatchRequest::create('/internal/v1/orders/bulk-dispatch', 'POST', $payload);
}

function fleetopsCancelOrderRequest(array $payload): CancelOrderRequest
{
    return CancelOrderRequest::create('/internal/v1/orders/cancel', 'POST', $payload);
}

test('internal order controller bulk cancel skips already canceled and canceled tracking statuses', function () {
    $created                                                               = fleetopsInternalOrderLifecycleOrder('order-created', 'created', 'tracking-created');
    $alreadyCanceled                                                       = fleetopsInternalOrderLifecycleOrder('order-canceled', 'canceled', 'tracking-canceled');
    $trackingCanceled                                                      = fleetopsInternalOrderLifecycleOrder('order-tracking-canceled', 'created', 'tracking-canceled-status');
    $controller                                                            = fleetopsInternalOrderLifecycleController([$created, $alreadyCanceled, $trackingCanceled]);
    $controller->trackingStatusExists['tracking-canceled-status:CANCELED'] = true;

    $response = $controller->bulkCancel(fleetopsBulkActionRequest([
        'ids' => ['order-created', 'order-canceled', 'order-tracking-canceled'],
    ]))->getData(true);

    expect($response)->toBe([
        'status'     => 'OK',
        'message'    => 'Canceled 3 orders',
        'count'      => 3,
        'failed'     => ['order-canceled', 'order-tracking-canceled'],
        'successful' => ['order-created'],
    ])
        ->and($created->canceledForTest)->toBeTrue()
        ->and($alreadyCanceled->canceledForTest)->toBeFalse()
        ->and($trackingCanceled->canceledForTest)->toBeFalse()
        ->and($created->queried_ids)->toBe(['order-created', 'order-canceled', 'order-tracking-canceled']);
});

test('internal order controller bulk dispatch only dispatches created uncanceled orders', function () {
    $created                                                               = fleetopsInternalOrderLifecycleOrder('order-created', 'created', 'tracking-created');
    $started                                                               = fleetopsInternalOrderLifecycleOrder('order-started', 'started', 'tracking-started');
    $trackingCanceled                                                      = fleetopsInternalOrderLifecycleOrder('order-tracking-canceled', 'created', 'tracking-canceled-status');
    $controller                                                            = fleetopsInternalOrderLifecycleController([$created, $started, $trackingCanceled]);
    $controller->trackingStatusExists['tracking-canceled-status:CANCELED'] = true;

    $response = $controller->bulkDispatch(fleetopsBulkDispatchRequest([
        'ids' => ['order-created', 'order-started', 'order-tracking-canceled'],
    ]))->getData(true);

    expect($response)->toBe([
        'status'     => 'OK',
        'message'    => 'Dispatched 3 orders',
        'count'      => 3,
        'failed'     => ['order-started', 'order-tracking-canceled'],
        'successful' => ['order-created'],
    ])
        ->and($created->dispatchedForTest)->toBeTrue()
        ->and($started->dispatchedForTest)->toBeFalse()
        ->and($trackingCanceled->dispatchedForTest)->toBeFalse();
});

test('internal order controller bulk assign driver deduplicates orders and queues notifications', function () {
    $controller = fleetopsInternalOrderLifecycleController();
    $driverUuid = '11111111-1111-4111-8111-111111111111';
    $orderA     = '22222222-2222-4222-8222-222222222222';
    $orderB     = '33333333-3333-4333-8333-333333333333';

    $response = $controller->bulkAssignDriver(fleetopsBulkActionRequest([
        'ids'    => [$orderA, $orderA, $orderB],
        'driver' => $driverUuid,
    ]))->getData(true);

    expect($response)->toBe([
        'status'  => 'OK',
        'message' => 'Queued assignment of driver (Ada Driver) to 2 orders',
        'count'   => 2,
    ])
        ->and($controller->driver->lookup_uuid)->toBe($driverUuid)
        ->and($controller->transactions)->toBe(1)
        ->and($controller->assignedOrderUuids)->toBe([$orderA, $orderB])
        ->and($controller->assignedDriverUuid)->toBe($driverUuid)
        ->and($controller->bulkNotification)->toBe([[$orderA, $orderB], $driverUuid]);

    $controller = fleetopsInternalOrderLifecycleController();
    $controller->bulkAssignDriver(fleetopsBulkActionRequest([
        'ids'    => [$orderA],
        'driver' => $driverUuid,
        'silent' => true,
    ]));

    expect($controller->bulkNotification)->toBe([]);
});

test('internal order controller reports invalid bulk assign driver selections', function () {
    $controller         = fleetopsInternalOrderLifecycleController();
    $controller->driver = null;

    $response = $controller->bulkAssignDriver(fleetopsBulkActionRequest([
        'ids'    => ['22222222-2222-4222-8222-222222222222'],
        'driver' => '11111111-1111-4111-8111-111111111111',
    ]));

    expect($response->getData(true))->toBe([
        'error' => 'Invalid driver selected to assign orders to.',
    ]);
});

test('internal order controller cancels and dispatches individual orders', function () {
    $order      = fleetopsInternalOrderLifecycleOrder('order-uuid', 'created');
    $controller = fleetopsInternalOrderLifecycleController([$order]);

    expect($controller->cancel(fleetopsCancelOrderRequest([
        'order' => 'order-uuid',
    ]))->getData(true))->toBe([
        'status'  => 'OK',
        'message' => 'Order was canceled',
        'order'   => 'order-uuid',
    ])
        ->and($order->lookup_uuid)->toBe('order-uuid')
        ->and($order->canceledForTest)->toBeTrue();

    $order      = fleetopsInternalOrderLifecycleOrder('order-dispatch', 'created');
    $controller = fleetopsInternalOrderLifecycleController([$order]);

    expect($controller->dispatchOrder(new Request([
        'order' => 'order-public',
    ]))->getData(true))->toBe([
        'status'  => 'OK',
        'message' => 'Order was dispatched',
        'order'   => 'order-dispatch',
    ])
        ->and($order->lookup_id)->toBe('order-public')
        ->and($order->lookup_with)->toBe(['orderConfig', 'driverAssigned'])
        ->and($order->dispatchedWithActivityForTest)->toBeTrue();
});

test('internal order controller reports individual dispatch validation branches', function () {
    $controller        = fleetopsInternalOrderLifecycleController();
    $controller->order = null;

    expect($controller->dispatchOrder(new Request([
        'order' => 'missing-order',
    ]))->getData(true))->toBe([
        'error' => 'No order found to dispatch.',
    ]);

    $order                        = fleetopsInternalOrderLifecycleOrder('order-no-config', 'created');
    $order->hasOrderConfigForTest = false;
    $controller                   = fleetopsInternalOrderLifecycleController([$order]);

    expect($controller->dispatchOrder(new Request([
        'order' => 'order-no-config',
    ]))->getData(true))->toBe([
        'error' => 'No order config found for dispatch.',
    ]);

    $order                           = fleetopsInternalOrderLifecycleOrder('order-no-driver', 'created');
    $order->hasDriverAssignedForTest = false;
    $controller                      = fleetopsInternalOrderLifecycleController([$order]);

    expect($controller->dispatchOrder(new Request([
        'order' => 'order-no-driver',
    ]))->getData(true))->toBe([
        'error' => 'No driver assigned to dispatch!',
    ]);

    $order                             = fleetopsInternalOrderLifecycleOrder('order-dispatched', 'created');
    $order->dispatchedAttributeForTest = true;
    $controller                        = fleetopsInternalOrderLifecycleController([$order]);

    expect($controller->dispatchOrder(new Request([
        'order' => 'order-dispatched',
    ]))->getData(true))->toBe([
        'error' => 'Order has already been dispatched!',
    ]);
});

test('internal order controller returns tracker info and waypoint etas', function () {
    $order                 = fleetopsInternalOrderLifecycleOrder('order-tracker', 'started');
    $order->trackerForTest = new FleetOpsInternalOrderLifecycleTrackerFake($order);
    $controller            = fleetopsInternalOrderLifecycleController([$order]);

    $tracker = $controller->trackerInfo(new Request([
        'provider'        => 'osrm',
        'fallbacks'       => true,
        'traffic_enabled' => false,
        'ignored'         => 'value',
    ]), 'order-tracker')->getData(true);

    expect($tracker)->toBe([
        'tracker' => 'info',
        'options' => [
            'provider'        => 'osrm',
            'fallbacks'       => true,
            'traffic_enabled' => false,
        ],
    ])
        ->and($order->trackerForTest->toArrayOptions)->toBe([
            'provider'        => 'osrm',
            'fallbacks'       => true,
            'traffic_enabled' => false,
        ]);

    $etas = $controller->waypointEtas(new Request([
        'provider'        => 'google',
        'fallbacks'       => false,
        'traffic_enabled' => true,
    ]), 'order-tracker')->getData(true);

    expect($etas)->toBe([
        'eta'     => [['stop' => 'dropoff']],
        'options' => [
            'provider'        => 'google',
            'fallbacks'       => false,
            'traffic_enabled' => true,
        ],
    ])
        ->and($order->trackerForTest->etaOptions)->toBe([
            'provider'        => 'google',
            'fallbacks'       => false,
            'traffic_enabled' => true,
        ]);

    $controller->order = null;

    expect($controller->trackerInfo(new Request(), 'missing-order')->getData(true))->toBe([
        'error' => 'No order found.',
    ])
        ->and($controller->waypointEtas(new Request(), 'missing-order')->getData(true))->toBe([
            'error' => 'No order found.',
        ]);
});
