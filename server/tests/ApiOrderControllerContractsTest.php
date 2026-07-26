<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Support\OrderTracker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiOrderCrudControllerProbe extends OrderController
{
    public ?FleetOpsApiOrderCrudFake $order = null;
    public mixed $matrix                    = null;
    public array $createdProofs             = [];
    public bool $orderNotFound              = false;
    public mixed $resolvedSubject           = null;

    protected function findOrder(string $id, array $with = [], array $withCount = []): Order
    {
        if ($this->orderNotFound) {
            throw new ModelNotFoundException();
        }

        $this->order ??= new FleetOpsApiOrderCrudFake();
        $this->order->lookups[] = [$id, $with, $withCount];

        return $this->order;
    }

    protected function drivingDistanceAndTime(mixed $origin, mixed $destination): mixed
    {
        return $this->matrix ?? (object) ['distance' => 1200, 'time' => 360];
    }

    protected function resolveSubject(Order $order, ?string $type, ?string $subjectId = null): mixed
    {
        return $this->resolvedSubject;
    }

    protected function createProof(array $input): Proof
    {
        $this->createdProofs[] = $input;

        $proof = new Proof();
        $proof->setRawAttributes(array_merge(['uuid' => 'proof-uuid'], $input));

        return $proof;
    }

    protected function orderResource(Order $order)
    {
        return ['resource' => 'order', 'order' => $order];
    }

    protected function deletedOrderResource(Order $order)
    {
        return ['resource' => 'deleted-order', 'order' => $order];
    }

    protected function proofResource(Proof $proof)
    {
        return ['resource' => 'proof', 'proof' => $proof];
    }

    protected function jsonResponse(mixed $payload, int $status = 200)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

class FleetOpsApiOrderCrudFake extends Order
{
    public array $lookups                  = [];
    public array $loaded                   = [];
    public array $updates                  = [];
    public bool $deletedForTest            = false;
    public bool $dispatchedForTest         = false;
    public bool $dispatchActivityInserted  = false;
    public bool $cancelledForTest          = false;
    public bool $hasDriverAssignedForTest  = true;
    public bool $adhocForTest              = false;
    public bool $dispatchedFlagForTest     = false;
    public FleetOpsApiOrderTrackerFake $trackerFake;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->trackerFake = new FleetOpsApiOrderTrackerFake($this);
        $this->uuid        = $attributes['uuid'] ?? 'order-uuid';
        $this->payload     = (object) [
            'pickup'    => (object) ['public_id' => 'pickup-public'],
            'dropoff'   => (object) ['public_id' => 'dropoff-public'],
            'waypoints' => collect([(object) ['public_id' => 'waypoint-public']]),
        ];
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
        return $this->dispatchedFlagForTest;
    }

    public function load($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }

    public function dispatch(bool $save = true): Order
    {
        $this->dispatchedForTest = true;

        return $this;
    }

    public function insertDispatchActivity(): Order
    {
        $this->dispatchActivityInserted = true;

        return $this;
    }

    public function cancel()
    {
        $this->cancelledForTest = true;

        return $this;
    }

    public function tracker(): OrderTracker
    {
        return $this->trackerFake;
    }
}

class FleetOpsApiOrderTrackerFake extends OrderTracker
{
    public array $toArrayOptions = [];
    public array $etaOptions     = [];
    public bool $throwOnTrack    = false;

    public function toArray(array $options = []): array
    {
        if ($this->throwOnTrack) {
            throw new RuntimeException('tracking failed');
        }

        $this->toArrayOptions = $options;

        return ['tracking' => 'ok', 'options' => $options];
    }

    public function eta(array $options = []): array
    {
        if ($this->throwOnTrack) {
            throw new RuntimeException('eta failed');
        }

        $this->etaOptions = $options;

        return ['eta' => 600, 'options' => $options];
    }
}

test('api order controller finds deletes and updates distance matrices without database records', function () {
    $order              = new FleetOpsApiOrderCrudFake();
    $controller         = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order  = $order;
    $controller->matrix = (object) ['distance' => 2400, 'time' => 720];

    $found    = $controller->find('order-public', new Request());
    $matrix   = $controller->getDistanceMatrix('order-public');
    $deleted  = $controller->delete('order-public', new Request());

    expect($found)->toBe(['resource' => 'order', 'order' => $order])
        ->and($matrix)->toBe(['json' => $controller->matrix, 'status' => 200])
        ->and($order->updates[0])->toBe(['distance' => 2400, 'time' => 720])
        ->and($deleted)->toBe(['resource' => 'deleted-order', 'order' => $order])
        ->and($order->deletedForTest)->toBeTrue()
        ->and($order->lookups[0][0])->toBe('order-public')
        ->and($order->loaded)->toContain(['payload', 'payload.waypoints', 'payload.pickup', 'payload.dropoff']);
});

test('api order controller dispatches cancels optimizes tracks and estimates orders', function () {
    $order             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $dispatched = $controller->dispatchOrder('order-public');
    $cancelled  = $controller->cancelOrder('order-public');
    $optimized  = $controller->optimize('order-public');
    $tracked    = $controller->trackerData(new Request(['provider' => 'test', 'fallbacks' => true]), 'order-public');
    $eta        = $controller->etaData(new Request(['traffic_enabled' => true]), 'order-public');

    expect($dispatched)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->dispatchedForTest)->toBeTrue()
        ->and($order->dispatchActivityInserted)->toBeTrue()
        ->and($cancelled)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->cancelledForTest)->toBeTrue()
        ->and($optimized)->toBe(['resource' => 'order', 'order' => $order])
        ->and($tracked)->toBe(['json' => ['tracking' => 'ok', 'options' => ['provider' => 'test', 'fallbacks' => true]], 'status' => 200])
        ->and($eta)->toBe(['json' => ['eta' => 600, 'options' => ['traffic_enabled' => true]], 'status' => 200]);
});

test('api order controller reports missing and invalid dispatch tracking branches', function () {
    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    $expectedJson = ['json' => ['error' => 'Order resource not found.'], 'status' => 404];

    expect($controller->find('missing-order', new Request()))->toBe($expectedJson)
        ->and($controller->delete('missing-order', new Request()))->toBe($expectedJson)
        ->and($controller->getDistanceMatrix('missing-order'))->toBe($expectedJson)
        ->and($controller->dispatchOrder('missing-order'))->toBe($expectedJson)
        ->and($controller->cancelOrder('missing-order'))->toBe($expectedJson)
        ->and($controller->optimize('missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->trackerData(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->etaData(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404]);

    $order                           = new FleetOpsApiOrderCrudFake();
    $order->hasDriverAssignedForTest = false;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->dispatchOrder('order-public'))->toBe(['apiError' => 'No driver assigned to dispatch!', 'status' => 400]);

    $order                        = new FleetOpsApiOrderCrudFake();
    $order->dispatchedFlagForTest = true;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->dispatchOrder('order-public'))->toBe(['apiError' => 'Order has already been dispatched!', 'status' => 400]);

    $order                            = new FleetOpsApiOrderCrudFake();
    $order->trackerFake->throwOnTrack = true;

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    expect($controller->trackerData(new Request(), 'order-public'))->toBe(['apiError' => 'An error occured trying to track order.', 'status' => 404])
        ->and($controller->etaData(new Request(), 'order-public'))->toBe(['apiError' => 'An error occured trying to track order.', 'status' => 404]);
});

test('api order controller captures QR proof payloads and validates error branches', function () {
    session(['company' => 'company-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $proof = $controller->captureQrScan(new Request([
        'code'     => 'subject-uuid',
        'raw_data' => 'raw-qr-data',
        'data'     => ['scan' => true],
    ]), 'order-public', 'waypoint_subject');

    expect($proof['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Verified by QR Code Scan',
            'raw_data'     => 'raw-qr-data',
            'data'         => ['scan' => true],
        ])
        ->and($controller->captureQrScan(new Request(), 'order-public'))->toBe(['apiError' => 'No QR code data to capture.', 'status' => 400]);

    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    expect($controller->captureQrScan(new Request(['code' => 'subject-uuid']), 'missing-order'))->toBe([
        'apiError' => 'Order resource not found.',
        'status'   => 404,
    ]);

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = new FleetOpsApiOrderCrudFake();

    expect($controller->captureQrScan(new Request(['code' => 'subject-uuid']), 'order-public', 'waypoint_missing'))->toBe([
        'apiError' => 'Unable to capture QR code data.',
        'status'   => 400,
    ]);

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    expect($controller->captureQrScan(new Request(['code' => 'wrong-code']), 'order-public', 'waypoint_subject'))->toBe([
        'apiError' => 'Unable to validate QR code data.',
        'status'   => 400,
    ]);
});
