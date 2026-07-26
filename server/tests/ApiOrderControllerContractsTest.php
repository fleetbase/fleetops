<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\OrderController;
use Fleetbase\FleetOps\Http\Requests\ScheduleOrderRequest;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Support\OrderTracker;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FleetOpsApiOrderCrudControllerProbe extends OrderController
{
    public ?FleetOpsApiOrderCrudFake $order = null;
    public mixed $matrix                    = null;
    public array $createdProofs             = [];
    public array $createdFiles              = [];
    public array $storedFiles               = [];
    public array $proofCollections          = [];
    public array $commentCollections        = [];
    public mixed $entityEditingSettings     = null;
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

        $proof = new FleetOpsApiOrderProofFake();
        $proof->setRawAttributes(array_merge(['uuid' => 'proof-uuid', 'public_id' => 'proof_public'], $input));

        return $proof;
    }

    protected function createFile(array $input): File
    {
        $this->createdFiles[] = $input;

        $file = new FleetOpsApiOrderFileFake();
        $file->setRawAttributes(array_merge(['uuid' => 'file-uuid'], $input));

        return $file;
    }

    protected function putStorage(string $disk, string $path, string $contents): void
    {
        $this->storedFiles[] = compact('disk', 'path', 'contents');
    }

    protected function entityEditingSettings(): mixed
    {
        return $this->entityEditingSettings;
    }

    protected function defaultCompanyTimezone(): string
    {
        return 'UTC';
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

    protected function proofsForSubject(Order $order, mixed $subject): Collection
    {
        return collect([
            (new FleetOpsApiOrderProofFake())->setRawAttributes([
                'uuid'         => 'proof-one',
                'order_uuid'   => $order->uuid,
                'subject_uuid' => $subject->uuid,
            ]),
        ]);
    }

    protected function proofResourceCollection($proofs)
    {
        $this->proofCollections[] = $proofs;

        return ['resource' => 'proofs', 'proofs' => $proofs->values()->all()];
    }

    protected function commentResourceCollection($comments)
    {
        $this->commentCollections[] = $comments;

        return ['resource' => 'comments', 'comments' => $comments->values()->all()];
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
    public bool $savedForTest              = false;
    public bool $refreshedForTest          = false;
    public FleetOpsApiOrderTrackerFake $trackerFake;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->trackerFake = new FleetOpsApiOrderTrackerFake($this);
        $this->uuid        = $attributes['uuid'] ?? 'order-uuid';
        $this->status      = $attributes['status'] ?? 'created';
        $this->payload     = (object) [
            'pickup'    => (object) ['public_id' => 'pickup-public'],
            'dropoff'   => (object) ['public_id' => 'dropoff-public'],
            'waypoints' => collect([(object) ['public_id' => 'waypoint-public']]),
        ];
        $this->comments = collect([(object) ['body' => 'Looks good']]);
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

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function refresh()
    {
        $this->refreshedForTest = true;

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

class FleetOpsApiOrderProofFake extends Proof
{
    public array $updates = [];
    public bool $saved    = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }
}

class FleetOpsApiOrderFileFake extends File
{
    public mixed $keyedTo = null;

    public function setKey($model, $type = null): File
    {
        $this->keyedTo = $model;

        return $this;
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

class FleetOpsApiOrderScheduleRequestFake extends ScheduleOrderRequest
{
    public function __construct(private readonly array $inputForTest)
    {
        parent::__construct();
    }

    public function input($key = null, $default = null)
    {
        if ($key === null) {
            return $this->inputForTest;
        }

        return data_get($this->inputForTest, $key, $default);
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

test('api order controller schedules orders with timezone normalized dates', function () {
    $order             = new FleetOpsApiOrderCrudFake();
    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = $order;

    $request = new FleetOpsApiOrderScheduleRequestFake([
        'date'     => '2026-08-15',
        'time'     => '09:30:15',
        'timezone' => 'Asia/Singapore',
    ]);

    $response = $controller->scheduleOrder('order-public', $request);

    expect($response)->toBe(['resource' => 'order', 'order' => $order])
        ->and($order->savedForTest)->toBeTrue()
        ->and($order->scheduled_at->timezoneName)->toBe('Asia/Singapore')
        ->and($order->scheduled_at->format('Y-m-d H:i:s'))->toBe('2026-08-15 09:30:15');
});

test('api order controller captures signature proof and stores file metadata', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $response = $controller->captureSignature(new Request([
        'signature' => 'data:image/png;base64,' . base64_encode('signature-bytes'),
        'remarks'   => 'Signed by receiver',
        'data'      => ['receiver' => 'Ada'],
        'disk'      => 'local',
        'bucket'    => 'local-bucket',
    ]), 'order-public', 'waypoint_subject');

    expect($response['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Signed by receiver',
            'data'         => ['receiver' => 'Ada'],
        ])
        ->and($controller->createdFiles[0])->toMatchArray([
            'company_uuid'  => 'company-uuid',
            'uploader_uuid' => 'user-uuid',
            'bucket'        => 'local-bucket',
            'type'          => 'signature',
        ])
        ->and($response['proof']->file_uuid)->toBe('file-uuid')
        ->and($response['proof']->saved)->toBeTrue()
        ->and($controller->storedFiles[0])->toBe([
            'disk'     => 'local',
            'path'     => 'uploads/company-uuid/signatures/proof_public.png',
            'contents' => 'signature-bytes',
        ]);
});

test('api order controller captures base64 photo proofs and links stored files', function () {
    session(['company' => 'company-uuid', 'user' => 'user-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                  = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order           = new FleetOpsApiOrderCrudFake();
    $controller->resolvedSubject = $subject;

    $response = $controller->capturePhoto(new Request([
        'photos'      => [base64_encode('photo-bytes')],
        'remarks'     => 'Photo received',
        'data'        => ['angle' => 'front'],
        'disk'        => 'local',
        'filesystems' => ['disks' => ['local' => ['bucket' => 'local-bucket']]],
    ]), 'order-public', 'waypoint_subject');

    expect($response['resource'])->toBe('proof')
        ->and($controller->createdProofs[0])->toMatchArray([
            'company_uuid' => 'company-uuid',
            'order_uuid'   => 'order-uuid',
            'subject_uuid' => 'subject-uuid',
            'remarks'      => 'Photo received',
            'raw_data'     => base64_encode('photo-bytes'),
            'data'         => ['angle' => 'front'],
        ])
        ->and($controller->createdFiles[0])->toMatchArray([
            'company_uuid'  => 'company-uuid',
            'uploader_uuid' => 'user-uuid',
            'bucket'        => 'local-bucket',
            'type'          => 'photo',
            'size'          => strlen('photo-bytes'),
        ])
        ->and($response['proof']->updates[0])->toBe(['file_uuid' => 'file-uuid'])
        ->and($controller->storedFiles[0])->toBe([
            'disk'     => 'local',
            'path'     => 'uploads/company-uuid/photos/proof_public.png',
            'contents' => 'photo-bytes',
        ]);
});

test('api order controller returns proof comment and editable field resources through seams', function () {
    session(['company' => 'company-uuid']);

    $subject = (object) ['uuid' => 'subject-uuid'];

    $controller                         = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order                  = new FleetOpsApiOrderCrudFake(['order_config_uuid' => 'config-uuid']);
    $controller->resolvedSubject        = $subject;
    $controller->entityEditingSettings  = ['config-uuid' => ['recipient_name' => true]];

    $proofs   = $controller->proofs(new Request(), 'order-public', 'waypoint_subject');
    $settings = $controller->getEditableEntityFields('order-public', new Request());
    $comments = $controller->orderComments('order-public');

    expect($proofs['resource'])->toBe('proofs')
        ->and($proofs['proofs'][0]->subject_uuid)->toBe('subject-uuid')
        ->and($settings)->toBe(['json' => ['recipient_name' => true], 'status' => 200])
        ->and($comments)->toBe(['resource' => 'comments', 'comments' => $controller->order->comments->values()->all()])
        ->and($controller->order->loaded)->toContain('comments');
});

test('api order controller reports proof signature photo and comments error branches', function () {
    $controller = new FleetOpsApiOrderCrudControllerProbe();

    expect($controller->captureSignature(new Request(), 'order-public'))->toBe(['apiError' => 'No signature data to capture.', 'status' => 400]);

    $controller                = new FleetOpsApiOrderCrudControllerProbe();
    $controller->orderNotFound = true;

    expect($controller->captureSignature(new Request(['signature' => base64_encode('signature')]), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->capturePhoto(new Request(['photos' => [base64_encode('photo')]]), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->proofs(new Request(), 'missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->getEditableEntityFields('missing-order', new Request()))->toBe(['apiError' => 'Order resource not found.', 'status' => 404])
        ->and($controller->orderComments('missing-order'))->toBe(['apiError' => 'Order resource not found.', 'status' => 404]);

    $controller        = new FleetOpsApiOrderCrudControllerProbe();
    $controller->order = new FleetOpsApiOrderCrudFake();

    expect($controller->captureSignature(new Request(['signature' => base64_encode('signature')]), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to capture signature data.', 'status' => 400])
        ->and($controller->capturePhoto(new Request(['photos' => [base64_encode('photo')]]), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to capture photo as proof.', 'status' => 400])
        ->and($controller->proofs(new Request(), 'order-public', 'waypoint_missing'))->toBe(['apiError' => 'Unable to retrieve proof of delivery for subject.', 'status' => 400]);
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
