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
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Type;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class FleetOpsInternalOrderLifecycleEventRecorder
{
    public static array $events = [];
}

if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\event')) {
    eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function event($event = null) { \FleetOpsInternalOrderLifecycleEventRecorder::$events[] = $event; return $event; }');
}

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalOrderLifecycleControllerProbe extends OrderController
{
    public Collection $orders;
    public ?FleetOpsInternalOrderLifecycleOrderFake $order            = null;
    public ?FleetOpsInternalOrderLifecycleDriverFake $driver          = null;
    public array $trackingStatusExists                                = [];
    public array $assignedOrderUuids                                  = [];
    public ?string $assignedDriverUuid                                = null;
    public array $bulkNotification                                    = [];
    public int $transactions                                          = 0;
    public array $trackingNumberStatuses                              = [];
    public ?FleetOpsInternalOrderLifecycleOrderFake $startOrder       = null;
    public ?FleetOpsInternalOrderLifecycleDriverFake $startDriver     = null;
    public ?FleetOpsInternalOrderLifecyclePayloadFake $startPayload   = null;
    public array $domainEvents                                        = [];
    public array $activityUpdates                                     = [];
    public bool $canPingDriver                                        = true;
    public bool $pingOrderMissing                                     = false;
    public bool $pingSendFails                                        = false;
    public ?FleetOpsInternalOrderLifecycleOrderFake $pingOrder        = null;
    public ?FleetOpsInternalOrderLifecycleOrderFake $labelOrder       = null;
    public ?FleetOpsInternalOrderLifecycleWaypointFake $labelWaypoint = null;
    public ?FleetOpsInternalOrderLifecycleEntityFake $labelEntity     = null;
    public Collection $customTypes;
    public array $defaultTypes                                        = [];
    public ?FleetOpsInternalOrderLifecycleOrderFake $proofOrder       = null;
    public ?FleetOpsInternalOrderLifecycleWaypointFake $proofWaypoint = null;
    public ?FleetOpsInternalOrderLifecycleEntityFake $proofEntity     = null;
    public Collection $proofResults;
    public array $exportDownloads                                    = [];
    public ?FleetOpsInternalOrderLifecycleOrderFake $trackingOrder   = null;
    public ?FleetOpsInternalOrderLifecycleOrderFake $scheduleOrder   = null;
    public ?FleetOpsInternalOrderLifecycleDriverFake $scheduleDriver = null;

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(OrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function appendProofInputsForTest(array &$incoming, mixed $value): void
    {
        $this->appendProofPhotoInputs($incoming, $value);
    }

    protected function findOrderForStart(?string $uuid): ?Order
    {
        $this->startOrder?->setAttribute('start_lookup_uuid', $uuid);

        return $this->startOrder;
    }

    protected function findDriverForStart(?string $uuid): ?Driver
    {
        $this->startDriver?->setAttribute('start_lookup_uuid', $uuid);

        return $this->startDriver;
    }

    protected function findPayloadForStart(?string $uuid): ?Payload
    {
        $this->startPayload?->setAttribute('start_lookup_uuid', $uuid);

        return $this->startPayload;
    }

    protected function dispatchDomainEvent(object $event): object
    {
        $this->domainEvents[] = $event::class;

        return $event;
    }

    protected function orderStartedEvent(Order $order): object
    {
        return new FleetOpsInternalOrderLifecycleStartedEventFake($order);
    }

    public function updateActivity(string $id, Request $request)
    {
        $this->activityUpdates[] = [$id, $request->input('activity')];

        return response()->json(['updated' => $id, 'activity' => data_get($request->input('activity'), 'code')]);
    }

    protected function canPingDriver(): bool
    {
        return $this->canPingDriver;
    }

    protected function findOrderForDriverPing(string $id): Order
    {
        if ($this->pingOrderMissing) {
            throw new ModelNotFoundException();
        }

        $this->pingOrder?->setAttribute('ping_lookup_id', $id);

        return $this->pingOrder ?? fleetopsInternalOrderLifecycleOrder('ping-order');
    }

    protected function sendDriverPing(Driver $driver, Order $order): void
    {
        if ($this->pingSendFails) {
            throw new RuntimeException('notification failed');
        }

        $driver->notifications[] = Fleetbase\FleetOps\Notifications\OrderPing::class;
    }

    protected function defaultOrderTypeConfig(): array
    {
        return $this->defaultTypes;
    }

    protected function customOrderTypes()
    {
        return $this->customTypes ?? collect();
    }

    protected function findOrderLabelSubject(string $id): ?Order
    {
        $this->labelOrder?->setAttribute('label_lookup_id', $id);

        return $this->labelOrder;
    }

    protected function findWaypointLabelSubject(string $id): ?Waypoint
    {
        $this->labelWaypoint?->setAttribute('label_lookup_id', $id);

        return $this->labelWaypoint;
    }

    protected function findEntityLabelSubject(string $id): ?Entity
    {
        $this->labelEntity?->setAttribute('label_lookup_id', $id);

        return $this->labelEntity;
    }

    protected function findOrderForProofs(string $id): ?Order
    {
        $this->proofOrder?->setAttribute('proof_lookup_id', $id);

        return $this->proofOrder;
    }

    protected function findWaypointProofSubject(Order $order, string $subjectId): ?Waypoint
    {
        $this->proofWaypoint?->setAttribute('proof_lookup_id', $subjectId);

        return $this->proofWaypoint;
    }

    protected function findEntityProofSubject(string $subjectId): ?Entity
    {
        $this->proofEntity?->setAttribute('proof_lookup_id', $subjectId);

        return $this->proofEntity;
    }

    protected function proofsForSubject(Order $order, Order|Waypoint|Entity $subject)
    {
        $this->proofResults ??= collect();
        $this->proofResults->each(fn (Proof $proof) => $proof->setAttribute('queried_subject_uuid', $subject->uuid));

        return $this->proofResults;
    }

    protected function downloadOrderExport(array $selections, string $fileName)
    {
        $this->exportDownloads[] = [$selections, $fileName];

        return ['download' => $fileName, 'selections' => $selections];
    }

    protected function findOrderByTrackingNumber(string $trackingNumber): ?Order
    {
        $this->trackingOrder?->setAttribute('tracking_lookup', $trackingNumber);

        return $this->trackingOrder;
    }

    protected function findOrderForSchedule(?string $id): ?Order
    {
        $this->scheduleOrder?->setAttribute('schedule_lookup_id', $id);

        return $this->scheduleOrder;
    }

    protected function findDriverForSchedule(string $id): ?Driver
    {
        $this->scheduleDriver?->setAttribute('schedule_lookup_id', $id);

        return $this->scheduleDriver;
    }

    protected function payloadUsesServiceStopActivity(?Payload $payload): bool
    {
        return false;
    }

    protected function makeTextResponse(string $text)
    {
        return $text;
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
    public bool $savedForTest                                          = false;
    public bool $quietlySavedForTest                                   = false;
    public ?FleetOpsInternalOrderLifecycleConfigFake $configForTest    = null;

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

    public function save(array $options = [])
    {
        $this->savedForTest = true;

        return true;
    }

    public function saveQuietly(array $options = [])
    {
        $this->quietlySavedForTest = true;

        return true;
    }

    public function setStartedAtAttribute($value): void
    {
        $this->attributes['started_at'] = $value;
    }

    public function setScheduledAtAttribute($value): void
    {
        $this->attributes['scheduled_at'] = $value;
    }

    public function getScheduledAtAttribute($value)
    {
        return $this->attributes['scheduled_at'] ?? $value;
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

    public function config(): ?OrderConfig
    {
        return $this->configForTest ??= new FleetOpsInternalOrderLifecycleConfigFake();
    }

    public function pdfLabelStream(): string
    {
        return 'stream:' . $this->uuid;
    }

    public function pdfLabel(): FleetOpsInternalOrderLifecyclePdfFake
    {
        return new FleetOpsInternalOrderLifecyclePdfFake('label:' . $this->uuid);
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
    public array $notifications = [];
    public bool $savedForTest   = false;
    public bool $throwOnNotify  = false;

    public function save(array $options = [])
    {
        $this->savedForTest = true;

        return true;
    }

    public function notify($instance)
    {
        if ($this->throwOnNotify) {
            throw new RuntimeException('notification failed');
        }

        $this->notifications[] = $instance::class;
    }
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

    public function pdfLabelStream(): string
    {
        return 'stream:' . $this->uuid;
    }

    public function pdfLabel(): FleetOpsInternalOrderLifecyclePdfFake
    {
        return new FleetOpsInternalOrderLifecyclePdfFake('label:' . $this->uuid);
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

    public function pdfLabelStream(): string
    {
        return 'stream:' . $this->uuid;
    }

    public function pdfLabel(): FleetOpsInternalOrderLifecyclePdfFake
    {
        return new FleetOpsInternalOrderLifecyclePdfFake('label:' . $this->uuid);
    }
}

class FleetOpsInternalOrderLifecycleConfigFake extends OrderConfig
{
    public function nextFirstActivity(Order|Waypoint|null $context = null): ?Activity
    {
        return new Activity(['code' => 'started']);
    }
}

class FleetOpsInternalOrderLifecycleStartedEventFake
{
    public function __construct(public Order $order)
    {
    }
}

class FleetOpsInternalOrderLifecyclePdfFake
{
    public function __construct(private readonly string $contents)
    {
    }

    public function output(): string
    {
        return $this->contents;
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

function fleetopsInternalOrderExportRequest(array $payload): ExportRequest
{
    return new class($payload) extends ExportRequest {
        public function __construct(private readonly array $payload)
        {
            parent::__construct([], $payload);
        }

        public function input($key = null, $default = null)
        {
            return data_get($this->payload, $key, $default);
        }

        public function array($key = null, $default = [])
        {
            $value = $this->input($key, $default);

            return is_array($value) ? $value : $default;
        }
    };
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

test('internal order controller start validates order driver and payload workflow', function () {
    $controller = fleetopsInternalOrderLifecycleController();

    expect($controller->start(new Request(['order' => 'missing-order']))->getData(true))->toBe([
        'error' => 'Unable to find order to start.',
    ]);

    $startedOrder = fleetopsInternalOrderLifecycleOrder('started-order', 'started');
    $startedOrder->forceFill(['started' => true]);
    $controller->startOrder = $startedOrder;

    expect($controller->start(new Request(['order' => 'started-order']))->getData(true))->toBe([
        'error' => 'Order has already been started.',
    ]);

    $order = fleetopsInternalOrderLifecycleOrder('start-order', 'dispatched');
    $order->forceFill([
        'driver_assigned_uuid' => 'driver-start',
        'payload_uuid'         => 'payload-start',
        'started'              => false,
    ]);
    $payload = fleetopsInternalOrderLifecyclePayload();

    $controller               = fleetopsInternalOrderLifecycleController([$order]);
    $controller->startOrder   = $order;
    $controller->startPayload = $payload;

    expect($controller->start(new Request(['order' => 'start-order']))->getData(true))->toBe([
        'error' => 'No driver assigned to order.',
    ]);

    $driver                  = new FleetOpsInternalOrderLifecycleDriverFake();
    $controller->startDriver = $driver;

    $response = $controller->start(new Request(['order' => 'start-order']))->getData(true);

    expect($response)->toBe([
        'updated'  => 'start-order',
        'activity' => 'started',
    ])
        ->and($order->start_lookup_uuid)->toBe('start-order')
        ->and($driver->start_lookup_uuid)->toBe('driver-start')
        ->and($payload->start_lookup_uuid)->toBe('payload-start')
        ->and($order->started)->toBeTrue()
        ->and($order->savedForTest)->toBeTrue()
        ->and($driver->current_job_uuid)->toBe('start-order')
        ->and($driver->savedForTest)->toBeTrue()
        ->and($controller->domainEvents)->toBe([FleetOpsInternalOrderLifecycleStartedEventFake::class])
        ->and($controller->activityUpdates[0][0])->toBe('start-order')
        ->and($controller->activityUpdates[0][1])->toBeInstanceOf(Activity::class)
        ->and($controller->activityUpdates[0][1]->code)->toBe('started');
});

test('internal order controller ping driver covers authorization and delivery outcomes', function () {
    $controller                = fleetopsInternalOrderLifecycleController();
    $controller->canPingDriver = false;

    expect($controller->pingDriver('order-ping')->getData(true))->toBe([
        'error' => 'Unauthorized.',
    ]);

    $controller                   = fleetopsInternalOrderLifecycleController();
    $controller->pingOrderMissing = true;

    expect($controller->pingDriver('order-ping')->getData(true))->toBe([
        'error' => 'Order resource not found.',
    ]);

    $order                 = fleetopsInternalOrderLifecycleOrder('order-ping');
    $order->setRelation('driverAssigned', null);
    $controller            = fleetopsInternalOrderLifecycleController([$order]);
    $controller->pingOrder = $order;

    expect($controller->pingDriver('order-ping')->getData(true))->toBe([
        'error' => 'Order does not have an assigned driver.',
    ]);

    $driver = new FleetOpsInternalOrderLifecycleDriverFake();
    $order->setRelation('driverAssigned', $driver);

    expect($controller->pingDriver('order-ping')->getData(true))->toBe([
        'status'  => 'ok',
        'message' => 'Driver app ping sent.',
    ])
        ->and($driver->notifications)->toBe([Fleetbase\FleetOps\Notifications\OrderPing::class]);

    $controller->pingSendFails = true;

    expect($controller->pingDriver('order-ping')->getData(true))->toBe([
        'error' => 'Unable to ping driver app.',
    ]);
});

test('internal order controller type list merges custom and default order types', function () {
    $controller = fleetopsInternalOrderLifecycleController();
    $custom     = new Type(['key' => 'parcel', 'name' => 'Custom Parcel']);

    $controller->customTypes  = collect([$custom]);
    $controller->defaultTypes = [
        ['key' => 'parcel', 'name' => 'Default Parcel'],
        ['key' => 'freight', 'name' => 'Freight'],
    ];

    $types = $controller->types()->getData(true);

    expect(array_column($types, 'key'))->toBe(['parcel', 'freight'])
        ->and($types[0]['name'])->toBe('Custom Parcel')
        ->and($types[1]['name'])->toBe('Freight');
});

test('internal order controller label renders supported subject formats', function () {
    $controller = fleetopsInternalOrderLifecycleController();
    $order      = fleetopsInternalOrderLifecycleOrder('order-label');
    $waypoint   = new FleetOpsInternalOrderLifecycleWaypointFake();
    $entity     = new FleetOpsInternalOrderLifecycleEntityFake();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-label'], true);
    $entity->setRawAttributes(['uuid' => 'entity-label'], true);

    $controller->labelOrder    = $order;
    $controller->labelWaypoint = $waypoint;
    $controller->labelEntity   = $entity;

    expect($controller->label('order_label', new Request()))->toBe('stream:order-label')
        ->and($controller->label('waypoint_label', new Request(['format' => 'text'])))->toBe('label:waypoint-label')
        ->and($controller->label('entity_label', new Request(['format' => 'base64']))->getData(true))->toBe([
            'data' => base64_encode('label:entity-label'),
        ]);

    $controller->labelOrder = null;

    expect($controller->label('order_missing', new Request())->getData(true))->toBe([
        'error' => 'Unable to render label.',
    ]);
});

test('internal order controller proof collection resolves order waypoint and entity subjects', function () {
    $order = fleetopsInternalOrderLifecycleOrder('order-proof');
    $order->forceFill(['payload_uuid' => 'payload-proof']);
    $waypoint = new FleetOpsInternalOrderLifecycleWaypointFake();
    $waypoint->setRawAttributes(['uuid' => 'waypoint-proof'], true);
    $entity = new FleetOpsInternalOrderLifecycleEntityFake();
    $entity->setRawAttributes(['uuid' => 'entity-proof'], true);
    $proof = new Proof();
    $proof->setRawAttributes(['uuid' => 'proof-one'], true);

    $controller                = fleetopsInternalOrderLifecycleController([$order]);
    $controller->proofOrder    = $order;
    $controller->proofWaypoint = $waypoint;
    $controller->proofEntity   = $entity;
    $controller->proofResults  = collect([$proof]);

    $orderProofs = $controller->proofs(new Request(), 'order-proof');

    expect($orderProofs->collection)->toHaveCount(1)
        ->and($proof->queried_subject_uuid)->toBe('order-proof');

    $waypointProofs = $controller->proofs(new Request(), 'order-proof', 'waypoint_proof');

    expect($waypointProofs->collection)->toHaveCount(1)
        ->and($waypoint->proof_lookup_id)->toBe('waypoint_proof')
        ->and($proof->queried_subject_uuid)->toBe('waypoint-proof');

    $entityProofs = $controller->proofs(new Request(), 'order-proof', 'entity_proof');

    expect($entityProofs->collection)->toHaveCount(1)
        ->and($entity->proof_lookup_id)->toBe('entity_proof')
        ->and($proof->queried_subject_uuid)->toBe('entity-proof');

    $controller->proofWaypoint = null;

    expect($controller->proofs(new Request(), 'order-proof', 'waypoint_missing')->getData(true))->toBe([
        'error' => 'Unable to retrieve proof of delivery for subject.',
    ]);
});

test('internal order controller export lookup and schedule endpoints use resolved resources', function () {
    $controller = fleetopsInternalOrderLifecycleController();

    $export = $controller->export(fleetopsInternalOrderExportRequest([
        'format'     => 'csv',
        'selections' => ['order-one', 'order-two'],
    ]));

    expect($export['download'])->toStartWith('order-')
        ->and($export['download'])->toEndWith('.csv')
        ->and($export['selections'])->toBe(['order-one', 'order-two'])
        ->and($controller->exportDownloads[0][0])->toBe(['order-one', 'order-two']);

    expect($controller->lookup(new Request())->getData(true))->toBe([
        'error' => 'No tracking number provided for lookup.',
    ])
        ->and($controller->lookup(new Request(['tracking' => 'TN-404']))->getData(true))->toBe([
            'error' => 'No order found using tracking number provided.',
        ]);

    $trackedOrder                 = fleetopsInternalOrderLifecycleOrder('tracked-order');
    $trackedOrder->trackerForTest = new FleetOpsInternalOrderLifecycleTrackerFake($trackedOrder);
    $controller->trackingOrder    = $trackedOrder;

    $lookup = $controller->lookup(new Request(['tracking' => 'TN-100']));

    expect($lookup->resource)->toBe($trackedOrder)
        ->and($trackedOrder->tracking_lookup)->toBe('TN-100')
        ->and($trackedOrder->loadedMissing)->toBe([['trackingNumber', 'payload', 'trackingStatuses']])
        ->and($trackedOrder->tracker_data)->toBe(['tracker' => 'info', 'options' => []])
        ->and($trackedOrder->eta)->toBe(['eta' => [['stop' => 'dropoff']], 'options' => []]);

    expect($controller->scheduleOrder(new Request(['order' => 'missing-order']))->getData(true))->toBe([
        'error' => 'No order found to schedule.',
    ]);

    $scheduledOrder = fleetopsInternalOrderLifecycleOrder('scheduled-order');
    $driver         = new FleetOpsInternalOrderLifecycleDriverFake();
    $driver->setRawAttributes(['uuid' => 'driver-scheduled'], true);
    $controller->scheduleOrder  = $scheduledOrder;
    $controller->scheduleDriver = $driver;

    $scheduleResponse = $controller->scheduleOrder(new Request([
        'order'        => 'scheduled-order',
        'scheduled_at' => '2026-08-01 09:30:00',
        'driver_id'    => 'driver-public',
    ]))->getData(true);

    expect($scheduleResponse['status'])->toBe('OK')
        ->and($scheduleResponse['order'])->toBe('scheduled-order')
        ->and($scheduledOrder->schedule_lookup_id)->toBe('scheduled-order')
        ->and($driver->schedule_lookup_id)->toBe('driver-public')
        ->and($scheduledOrder->driver_assigned_uuid)->toBe('driver-scheduled')
        ->and($scheduledOrder->quietlySavedForTest)->toBeTrue()
        ->and($scheduledOrder->scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-08-01 09:30:00');
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

test('internal order controller proof photo helpers normalize aliases and base64 inputs', function () {
    $controller = fleetopsInternalOrderLifecycleController();
    $encoded    = base64_encode('proof photo');
    $dataUri    = 'data:image/png;base64,' . $encoded;
    $invalid    = 'not-valid-base64';
    $file       = UploadedFile::fake()->image('proof.png', 16, 16);

    $request = new Request([
        'photos' => [$dataUri, [$dataUri, 42]],
        'photo'  => $encoded,
        'files'  => [$invalid],
        'file'   => null,
    ]);
    $request->files->set('file', $file);

    expect($controller->callHelper('collectProofPhotoInputs', $request))->toHaveCount(3)
        ->and($controller->callHelper('isValidBase64ProofPhoto', $dataUri))->toBeTrue()
        ->and($controller->callHelper('isValidBase64ProofPhoto', $encoded))->toBeTrue()
        ->and($controller->callHelper('isValidBase64ProofPhoto', ['not' => 'a string']))->toBeFalse()
        ->and($controller->callHelper('isValidBase64ProofPhoto', $invalid))->toBeFalse()
        ->and($controller->callHelper('proofPhotoInputFingerprint', $dataUri))->toBe($controller->callHelper('proofPhotoInputFingerprint', $encoded))
        ->and($controller->callHelper('proofPhotoInputFingerprint', ['unsupported']))->toBeNull();

    $incoming = [];
    $controller->appendProofInputsForTest($incoming, [$encoded, [$file, null], 99]);

    expect($incoming)->toBe([$encoded, $file, 99])
        ->and($controller->callHelper('dedupeProofPhotoInputs', [$dataUri, $encoded, $file, $file, ['skip']]))->toBe([$dataUri, $file]);
});

test('internal order controller waypoint helpers handle empty activity branches', function () {
    $controller = fleetopsInternalOrderLifecycleController();
    $payload    = fleetopsInternalOrderLifecyclePayload();
    $activity   = new Activity(['code' => 'arrived']);

    $payload->forceFill(['current_waypoint_uuid' => null]);
    $payload->waypointMarkersForTest = collect();
    $payload->setRelation('waypointMarkers', collect());

    expect($controller->callHelper('updateCurrentWaypointActivity', null, $activity, 'point'))->toBeNull()
        ->and($controller->callHelper('updateCurrentWaypointActivity', $payload, $activity))->toBeNull()
        ->and($controller->callHelper('payloadHasCurrentWaypointActivity', null, $activity))->toBeFalse()
        ->and($controller->callHelper('payloadHasCurrentWaypointActivity', $payload, $activity))->toBeFalse()
        ->and($controller->callHelper('advanceCurrentWaypointDestination', $payload))->toBeNull();
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
