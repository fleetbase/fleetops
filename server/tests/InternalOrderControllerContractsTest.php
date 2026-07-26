<?php

use Fleetbase\FleetOps\Flow\Activity;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\BulkDispatchRequest;
use Fleetbase\FleetOps\Http\Requests\CancelOrderRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\OrderTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsInternalOrderLifecycleEventRecorder
{
    public static array $events = [];
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function event($event = null) { \FleetOpsInternalOrderLifecycleEventRecorder::$events[] = $event; return $event; }');
}

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
    public array $trackingNumberStatuses                     = [];

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function findOrderRouteForEdit(string $uuid): ?Order
    {
        $this->order?->setAttribute('route_lookup_uuid', $uuid);

        return $this->order;
    }

    protected function orderResponse(Order $order): array
    {
        return ['order' => $order];
    }

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

    protected function trackingNumberStatus(string $trackingNumberUuid): ?TrackingStatus
    {
        return $this->trackingNumberStatuses[$trackingNumberUuid] ?? null;
    }
}

class FleetOpsInternalOrderLifecycleOrderFake extends Order
{
    public bool $canceledForTest                                       = false;
    public bool $dispatchedForTest                                     = false;
    public bool $dispatchedWithActivityForTest                         = false;
    public bool $hasDriverAssignedForTest                              = true;
    public bool $adhocForTest                                          = false;
    public bool $dispatchedAttributeForTest                            = false;
    public bool $hasOrderConfigForTest                                 = true;
    public ?FleetOpsInternalOrderLifecycleTrackerFake $trackerForTest  = null;
    public array $attachedFiles                                        = [];
    public array $customFieldValues                                    = [];
    public array $loadedMissing                                        = [];
    public array $loadedRelations                                      = [];

    public function attachFiles($files): self
    {
        $this->attachedFiles = $files;

        return $this;
    }

    public function syncCustomFieldValues(array $customFieldValues, array $options = []): array
    {
        $this->customFieldValues = $customFieldValues;

        return $customFieldValues;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

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

class FleetOpsInternalOrderLifecyclePayloadFake extends Payload
{
    public array $calls                           = [];
    public array $loadedMissing                   = [];
    public array $loadedRelations                 = [];
    public array $unsetRelations                  = [];
    public ?Place $pickupOrFirstWaypointForTest   = null;
    public ?Place $dropoffOrLastWaypointForTest   = null;
    public ?Place $currentWaypointForTest         = null;
    public ?Collection $waypointMarkersForTest    = null;

    public function setPickup($place, array $options = [])
    {
        $this->calls[] = ['setPickup', $place, $options];

        return $this;
    }

    public function setDropoff($place, array $options = [])
    {
        $this->calls[] = ['setDropoff', $place, $options];

        return $this;
    }

    public function setReturn($place, array $options = [])
    {
        $this->calls[] = ['setReturn', $place, $options];

        return $this;
    }

    public function removePlace($property, array $options = [])
    {
        $this->calls[] = ['removePlace', $property, $options];

        return $this;
    }

    public function updateWaypoints($waypoints = [])
    {
        $this->calls[] = ['updateWaypoints', $waypoints];

        return $this;
    }

    public function removeWaypoints()
    {
        $this->calls[] = ['removeWaypoints'];

        return $this;
    }

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        if ($this->waypointMarkersForTest && in_array('waypointMarkers.trackingNumber.status', (array) $relations, true)) {
            $this->setRelation('waypointMarkers', $this->waypointMarkersForTest);
        }

        return $this;
    }

    public function unsetRelation($relation)
    {
        $this->unsetRelations[] = $relation;

        return parent::unsetRelation($relation);
    }

    public function getPickupOrFirstWaypoint(): ?Place
    {
        return $this->pickupOrFirstWaypointForTest;
    }

    public function getDropoffOrLastWaypoint(): ?Place
    {
        return $this->dropoffOrLastWaypointForTest;
    }

    public function setCurrentWaypoint(Place|Waypoint $destination, bool $save = true): Payload
    {
        $this->currentWaypointForTest = $destination instanceof Place ? $destination : null;
        $this->calls[]                = ['setCurrentWaypoint', $destination, $save];

        return $this;
    }
}

class FleetOpsInternalOrderLifecycleDriverFake extends Driver
{
}

class FleetOpsInternalOrderLifecycleWaypointFake extends Waypoint
{
    public array $activities    = [];
    public array $loadedMissing = [];

    public function loadMissing($relations)
    {
        $this->loadedMissing[] = $relations;

        return $this;
    }

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activities[] = [$activity->code, $location, $proof];

        return 'activity-' . count($this->activities);
    }

    public function getPlace(): ?Place
    {
        return $this->place;
    }
}

class FleetOpsInternalOrderLifecycleEntityFake extends Entity
{
    public array $activities = [];

    public function insertActivity(Activity $activity, $location = [], $proof = null): string
    {
        $this->activities[] = [$activity->code, $location, $proof];

        return 'activity-' . count($this->activities);
    }
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

function fleetopsInternalOrderLifecyclePayload(?Place $startingDestination = null, ?Place $fallbackDestination = null): FleetOpsInternalOrderLifecyclePayloadFake
{
    $payload                                = new FleetOpsInternalOrderLifecyclePayloadFake();
    $payload->pickupOrFirstWaypointForTest  = $startingDestination;
    $payload->dropoffOrLastWaypointForTest  = $fallbackDestination;

    return $payload;
}

function fleetopsInternalOrderLifecyclePlace(string $uuid = 'place-uuid'): Place
{
    $place = new Place();
    $place->setRawAttributes(['uuid' => $uuid, 'public_id' => $uuid], true);

    return $place;
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

function fleetopsSuppressStrNullDeprecations(): Closure
{
    set_error_handler(function (int $severity, string $message): bool {
        return $severity === E_DEPRECATED && str_contains($message, 'mb_strtolower(): Passing null');
    });

    return function (): void {
        restore_error_handler();
    };
}

test('internal order controller after-update syncs files waypoints and custom fields', function () {
    $order   = fleetopsInternalOrderLifecycleOrder('order-route');
    $payload = fleetopsInternalOrderLifecyclePayload();
    $order->setRelation('payload', $payload);

    $controller = fleetopsInternalOrderLifecycleController([$order]);
    $controller->onAfterUpdate(new Request([
        'order' => [
            'files'               => ['file-one', 'file-two'],
            'payload'             => ['waypoints' => [['name' => 'Stop one']]],
            'custom_field_values' => ['fragile' => true],
        ],
    ]), $order);

    expect($order->attachedFiles)->toBe(['file-one', 'file-two'])
        ->and($order->loadedMissing)->toBe(['payload'])
        ->and($payload->calls)->toContain(['updateWaypoints', [['name' => 'Stop one']]])
        ->and($order->customFieldValues)->toBe(['fragile' => true]);
});

test('internal order controller edits order routes and refreshes current destination', function () {
    $startingDestination = fleetopsInternalOrderLifecyclePlace('starting-place');
    $payload             = fleetopsInternalOrderLifecyclePayload($startingDestination);
    $order               = fleetopsInternalOrderLifecycleOrder('order-route');
    $order->setRelation('payload', $payload);
    $controller = fleetopsInternalOrderLifecycleController([$order]);

    $response = $controller->editOrderRoute('order-route', new Request([
        'pickup'    => ['name' => 'Pickup'],
        'dropoff'   => ['name' => 'Dropoff'],
        'return'    => ['name' => 'Return'],
        'waypoints' => [['name' => 'Waypoint']],
    ]));

    expect($response)->toBe(['order' => $order])
        ->and($order->route_lookup_uuid)->toBe('order-route')
        ->and($payload->calls)->toContain(['setPickup', ['name' => 'Pickup'], ['save' => true]])
        ->and($payload->calls)->toContain(['setDropoff', ['name' => 'Dropoff'], ['save' => true]])
        ->and($payload->calls)->toContain(['setReturn', ['name' => 'Return'], ['save' => true]])
        ->and($payload->calls)->toContain(['updateWaypoints', [['name' => 'Waypoint']]])
        ->and($payload->calls)->toContain(['setCurrentWaypoint', $startingDestination, true])
        ->and($order->loadedRelations)->toBe([['payload.pickup', 'payload.dropoff', 'payload.return', 'payload.waypoints']]);
});

test('internal order controller clears route endpoints and falls back to dropoff destination', function () {
    $fallbackDestination = fleetopsInternalOrderLifecyclePlace('fallback-place');
    $payload             = fleetopsInternalOrderLifecyclePayload(null, $fallbackDestination);
    $order               = fleetopsInternalOrderLifecycleOrder('order-route');
    $order->setRelation('payload', $payload);
    $controller = fleetopsInternalOrderLifecycleController([$order]);

    $response = $controller->editOrderRoute('order-route', new Request([
        'pickup'  => null,
        'dropoff' => null,
        'return'  => null,
    ]));

    expect($response)->toBe(['order' => $order])
        ->and($payload->calls)->toContain(['removePlace', 'pickup', ['save' => true]])
        ->and($payload->calls)->toContain(['removePlace', 'dropoff', ['save' => true]])
        ->and($payload->calls)->toContain(['removePlace', 'return', ['save' => true]])
        ->and($payload->calls)->toContain(['removeWaypoints'])
        ->and($payload->calls)->toContain(['setCurrentWaypoint', $fallbackDestination, true]);
});

test('internal order controller reports missing order route edits', function () {
    $controller        = fleetopsInternalOrderLifecycleController();
    $controller->order = null;

    expect($controller->editOrderRoute('missing-route', new Request())->getData(true))->toBe([
        'error' => 'Unable to find order to update route for.',
    ]);
});

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

test('internal order controller waypoint helpers detect waypoint state and proof objects', function () {
    $restore = fleetopsSuppressStrNullDeprecations();

    try {
        $controller = fleetopsInternalOrderLifecycleController();
        $payload    = fleetopsInternalOrderLifecyclePayload();
        $proof      = new Proof();
        $proof->setRawAttributes(['uuid' => 'proof-uuid', 'public_id' => 'proof_public'], true);
        $waypoint   = new FleetOpsInternalOrderLifecycleWaypointFake();
        $waypoint->setRawAttributes([
            'uuid'                 => 'waypoint-uuid',
            'public_id'            => 'waypoint_public',
            'place_uuid'           => 'place-current',
            'tracking_number_uuid' => 'tracking-complete',
            'order'                => 0,
        ], true);
        $payload->setRelation('waypoints', collect([fleetopsInternalOrderLifecyclePlace('waypoint-place')]));
        $payload->setRelation('waypointMarkers', collect([$waypoint]));

        $completeStatus = new TrackingStatus();
        $completeStatus->setRawAttributes(['code' => 'completed', 'complete' => true], true);
        $controller->trackingNumberStatuses['tracking-complete'] = $completeStatus;

        expect($controller->callHelper('payloadHasWaypoints', null))->toBeFalse()
            ->and($controller->callHelper('payloadHasWaypoints', $payload))->toBeTrue()
            ->and($controller->callHelper('waypointMarkerIsComplete', $waypoint))->toBeTrue()
            ->and($controller->callHelper('resolveProof', $proof))->toBe($proof)
            ->and($controller->callHelper('resolveProof', ['not' => 'proof']))->toBeNull();

        $waypointWithoutTracking = new FleetOpsInternalOrderLifecycleWaypointFake();
        $waypointWithoutTracking->setRawAttributes(['uuid' => 'waypoint-no-tracking', 'public_id' => 'waypoint_no_tracking'], true);

        expect($controller->callHelper('waypointMarkerIsComplete', $waypointWithoutTracking))->toBeFalse();
    } finally {
        $restore();
    }
});

test('internal order controller updates current waypoint activity and advances incomplete destinations', function () {
    $restore = fleetopsSuppressStrNullDeprecations();

    try {
        FleetOpsInternalOrderLifecycleEventRecorder::$events = [];
        $controller                                          = fleetopsInternalOrderLifecycleController();
        $order                                               = fleetopsInternalOrderLifecycleOrder('order-waypoint', 'started');
        $payload                                             = fleetopsInternalOrderLifecyclePayload();
        $payload->forceFill(['current_waypoint_uuid' => 'place-current']);
        $order->setRelation('payload', $payload);
        $payload->setRelation('order', $order);

        $currentPlace = fleetopsInternalOrderLifecyclePlace('place-current');
        $nextPlace    = fleetopsInternalOrderLifecyclePlace('place-next');
        $current      = new FleetOpsInternalOrderLifecycleWaypointFake();
        $current->setRawAttributes([
            'uuid'                 => 'waypoint-current',
            'public_id'            => 'waypoint_current',
            'place_uuid'           => 'place-current',
            'tracking_number_uuid' => 'tracking-current',
            'order'                => 0,
        ], true);
        $current->setRelation('place', $currentPlace);

        $next = new FleetOpsInternalOrderLifecycleWaypointFake();
        $next->setRawAttributes([
            'uuid'                 => 'waypoint-next',
            'public_id'            => 'waypoint_next',
            'place_uuid'           => 'place-next',
            'tracking_number_uuid' => 'tracking-next',
            'order'                => 1,
        ], true);
        $next->setRelation('place', $nextPlace);

        $entity = new FleetOpsInternalOrderLifecycleEntityFake();
        $entity->setRawAttributes(['uuid' => 'entity-current', 'destination_uuid' => 'place-current'], true);

        $payload->waypointMarkersForTest = collect([$current, $next]);
        $payload->setRelation('waypointMarkers', collect([$current, $next]));
        $payload->setRelation('entities', collect([$entity]));

        $completeStatus = new TrackingStatus();
        $completeStatus->setRawAttributes(['code' => 'completed', 'complete' => true], true);
        $incompleteStatus = new TrackingStatus();
        $incompleteStatus->setRawAttributes(['code' => 'arrived', 'complete' => false], true);
        $controller->trackingNumberStatuses = [
            'tracking-current' => $completeStatus,
            'tracking-next'    => $incompleteStatus,
        ];

        $activity = new Activity(['code' => 'arrived', 'complete' => true]);

        expect($controller->callHelper('updateCurrentWaypointActivity', $payload, $activity, 'point', 'proof'))->toBe($current)
            ->and($current->activities)->toBe([['arrived', 'point', 'proof']])
            ->and($entity->activities)->toBe([['arrived', 'point', 'proof']])
            ->and(FleetOpsInternalOrderLifecycleEventRecorder::$events)->toHaveCount(2)
            ->and($controller->callHelper('allWaypointMarkersComplete', null))->toBeFalse()
            ->and($controller->callHelper('allWaypointMarkersComplete', $payload))->toBeFalse()
            ->and($controller->callHelper('advanceCurrentWaypointDestination', null))->toBeNull();

        $advanced = $controller->callHelper('advanceCurrentWaypointDestination', $payload);

        expect($advanced)->toBe($next)
            ->and($payload->calls)->toContain(['setCurrentWaypoint', $next, true])
            ->and($payload->currentWaypoint)->toBe($nextPlace)
            ->and($payload->currentWaypointMarker)->toBe($next);
    } finally {
        $restore();
    }
});
