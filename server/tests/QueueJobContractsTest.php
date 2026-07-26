<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Jobs\FinalizeApiOrderCreation;
use Fleetbase\FleetOps\Jobs\FinalizeInternalOrderCreation;
use Fleetbase\FleetOps\Jobs\NotifyBulkAssignedDriver;
use Fleetbase\FleetOps\Jobs\ReplayPositions;
use Fleetbase\FleetOps\Jobs\SendPositionReplay;
use Fleetbase\FleetOps\Jobs\SimulateDrivingRoute;
use Fleetbase\FleetOps\Jobs\SimulateWaypointReached;
use Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Carbon;

class FleetOpsCheckGeofenceDwellProbe extends CheckGeofenceDwell
{
    public ?object $state               = null;
    public Driver|Vehicle|null $subject = null;
    public ?Zone $geofence              = null;
    public array $warnings              = [];
    public array $events                = [];

    protected function findDwellState(): ?object
    {
        return $this->state;
    }

    protected function findSubject(): Driver|Vehicle|null
    {
        return $this->subject;
    }

    protected function findGeofence(): ?Zone
    {
        return $this->geofence;
    }

    protected function fireDwellEvent(Driver|Vehicle $subject, $geofence, DateTimeInterface $enteredAt): void
    {
        $this->events[] = [$subject, $geofence, $enteredAt->format('Y-m-d H:i:s')];
    }

    protected function logWarning(string $message, array $context): void
    {
        $this->warnings[] = [$message, $context];
    }
}

class FleetOpsSocketClusterRecorder
{
    public array $records        = [];
    public ?Throwable $throwable = null;

    public function send($channel, array $data = []): bool
    {
        if ($this->throwable) {
            throw $this->throwable;
        }

        $this->records[] = [$channel, $data];

        return true;
    }
}

class FleetOpsSendPositionReplayProbe extends SendPositionReplay
{
    public FleetOpsSocketClusterRecorder $socket;
    public array $errors = [];

    public function __construct(string $channelId, Position $position, int $index, ?string $subjectUuid = null)
    {
        parent::__construct($channelId, $position, $index, $subjectUuid);

        $this->socket = new FleetOpsSocketClusterRecorder();
    }

    public function payload(): array
    {
        return $this->eventData();
    }

    protected function socket()
    {
        return $this->socket;
    }

    protected function logError(string $message): void
    {
        $this->errors[] = $message;
    }
}

class FleetOpsReplayPositionsProbe extends ReplayPositions
{
    public array $dispatches = [];
    public array $logs       = [];

    protected function dispatchReplayPosition(Position $position, int|string $index, float $offset)
    {
        $this->dispatches[] = [$position->uuid, $index, $offset];

        return null;
    }

    protected function logInfo(string $message): void
    {
        $this->logs[] = $message;
    }
}

class FleetOpsBulkAssignedOrderFake extends Order
{
    public bool $notified       = false;
    public ?Throwable $throwing = null;

    public function notifyDriverAssigned(): void
    {
        if ($this->throwing) {
            throw $this->throwing;
        }

        $this->notified = true;
    }
}

class FleetOpsNotifyBulkAssignedDriverProbe extends NotifyBulkAssignedDriver
{
    public ?Driver $driver = null;
    public array $orders   = [];
    public array $warnings = [];

    protected function findDriver(): ?Driver
    {
        return $this->driver;
    }

    protected function ordersForNotification(): iterable
    {
        return $this->orders;
    }

    protected function logNotificationFailure(Order $order, Throwable $e): void
    {
        $this->warnings[] = [$order->uuid, $e->getMessage()];
    }
}

class FleetOpsFinalizeOrderFake extends Order
{
    public array $calls = [];

    public function notifyDriverAssigned(): void
    {
        $this->calls[] = 'notifyDriverAssigned';
    }

    public function setPreliminaryDistanceAndTime(): void
    {
        $this->calls[] = 'setPreliminaryDistanceAndTime';
    }

    public function purchaseServiceQuote($serviceQuote, $meta = [])
    {
        $this->calls[] = ['purchaseServiceQuote', $serviceQuote?->uuid];
    }

    public function dispatchWithActivity(): Order
    {
        $this->calls[] = 'dispatchWithActivity';

        return $this;
    }
}

class FleetOpsFinalizeApiOrderCreationProbe extends FinalizeApiOrderCreation
{
    public ?Order $order               = null;
    public ?ServiceQuote $serviceQuote = null;
    public array $events               = [];

    protected function findOrder(): ?Order
    {
        return $this->order;
    }

    protected function findServiceQuote(): ?ServiceQuote
    {
        return $this->serviceQuote;
    }

    protected function fireOrderReady(Order $order): void
    {
        $this->events[] = $order->uuid;
    }
}

class FleetOpsFinalizeInternalOrderCreationProbe extends FinalizeInternalOrderCreation
{
    public ?Order $order = null;
    public array $events = [];

    protected function findOrder(): ?Order
    {
        return $this->order;
    }

    protected function fireOrderReady(Order $order): void
    {
        $this->events[] = $order->uuid;
    }
}

class FleetOpsSimulateDrivingRouteProbe extends SimulateDrivingRoute
{
    public array $made       = [];
    public array $dispatched = [];

    protected function makeWaypointReachedJob($waypoint, array $additionalData): SimulateWaypointReached
    {
        $this->made[] = [$waypoint, $additionalData];

        return new SimulateWaypointReached($this->driver, $waypoint, $additionalData);
    }

    protected function dispatchWaypointChain($firstWaypoint, array $chain): void
    {
        $this->dispatched[] = [$firstWaypoint, $chain];
    }
}

class FleetOpsFuelProviderConnectionFake extends FuelProviderConnection
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return true;
    }
}

class FleetOpsFuelProviderSyncRunFake extends FuelProviderSyncRun
{
    public array $updates = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;

        return true;
    }
}

class FleetOpsFuelProviderServiceFake extends FuelProviderService
{
    public array $calls          = [];
    public ?Throwable $throwable = null;

    public function __construct()
    {
    }

    public function syncTransactions(FuelProviderConnection $connection, ?Carbon $from = null, ?Carbon $to = null, array $options = [], ?FuelProviderSyncRun $syncRun = null): array
    {
        $this->calls[] = [$connection, $from, $to, $options, $syncRun];

        if ($this->throwable) {
            throw $this->throwable;
        }

        return ['imported' => 2];
    }
}

class FleetOpsSyncFuelProviderTransactionsJobProbe extends SyncFuelProviderTransactionsJob
{
    public FleetOpsFuelProviderConnectionFake $fakeConnection;
    public ?FleetOpsFuelProviderSyncRunFake $syncRun = null;

    protected function findConnection(): FuelProviderConnection
    {
        return $this->fakeConnection;
    }

    protected function findSyncRun(): ?FuelProviderSyncRun
    {
        return $this->syncRun;
    }
}

class FleetOpsTelematicConnectionFake extends Telematic
{
    public bool $saved = false;

    public function save(array $options = []): bool
    {
        $this->saved = true;

        return true;
    }
}

class FleetOpsTelematicProviderFake implements TelematicProviderInterface
{
    public array $connections    = [];
    public array $tests          = [];
    public ?Throwable $throwable = null;

    public function connect(Telematic $telematic): void
    {
        $this->connections[] = $telematic;
    }

    public function testConnection(array $credentials): array
    {
        $this->tests[] = $credentials;

        if ($this->throwable) {
            throw $this->throwable;
        }

        return [
            'success'  => true,
            'message'  => 'Connection verified',
            'metadata' => ['account' => 'demo'],
        ];
    }

    public function fetchDevices(array $options = []): array
    {
        return ['devices' => [], 'next_cursor' => null, 'has_more' => false];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return ['external_id' => $externalId];
    }

    public function normalizeDevice(array $payload): array
    {
        return $payload;
    }

    public function normalizeEvent(array $payload): array
    {
        return $payload;
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        return true;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return ['devices' => [], 'events' => [], 'sensors' => []];
    }

    public function getCredentialSchema(): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsDiscovery(): bool
    {
        return false;
    }

    public function getRateLimits(): array
    {
        return ['requests_per_minute' => 60, 'burst_size' => 10];
    }
}

class FleetOpsTelematicProviderRegistryFake extends TelematicProviderRegistry
{
    public array $resolved = [];

    public function __construct(public FleetOpsTelematicProviderFake $provider)
    {
    }

    public function resolve(string $key): TelematicProviderInterface
    {
        $this->resolved[] = $key;

        return $this->provider;
    }
}

class FleetOpsTelematicConnectionServiceFake extends TelematicService
{
    public array $credentials = ['token' => 'secret'];
    public array $records     = [];

    public function __construct()
    {
    }

    public function getCredentials(Telematic $telematic): array
    {
        return $this->credentials;
    }

    public function recordConnectionTest(Telematic $telematic, array $result): void
    {
        $this->records[] = [$telematic, $result];
    }
}

function fleetOpsReplayPosition(): Position
{
    $position = new Position();
    $position->setRawAttributes([
        'uuid'         => 'position-uuid',
        'subject_uuid' => 'vehicle-uuid',
        'coordinates'  => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
        'heading'      => null,
        'speed'        => 44,
        'altitude'     => null,
        'created_at'   => Carbon::parse('2026-03-01 10:15:30'),
    ], true);

    return $position;
}

function fleetOpsReplayPositionAt(string $uuid, string $createdAt): Position
{
    $position = fleetOpsReplayPosition();
    $position->setRawAttributes(array_merge($position->getAttributes(), [
        'uuid'       => $uuid,
        'created_at' => Carbon::parse($createdAt),
    ]), true);

    return $position;
}

test('check geofence dwell exits for stale state and logs missing subject or geofence', function () {
    $job = new FleetOpsCheckGeofenceDwellProbe('driver-uuid', 'zone-uuid', 'zone');

    $job->handle();

    expect($job->events)->toBe([])
        ->and($job->warnings)->toBe([]);

    $job->state = (object) ['entered_at' => '2026-03-01 10:00:00'];
    $job->handle();

    expect($job->events)->toBe([])
        ->and($job->warnings)->toBe([
            ['CheckGeofenceDwell: Subject not found', ['subject_uuid' => 'driver-uuid', 'subject_type' => 'driver']],
        ]);

    $job->warnings = [];
    $job->subject  = new Driver();
    $job->handle();

    expect($job->events)->toBe([])
        ->and($job->warnings)->toBe([
            ['CheckGeofenceDwell: Geofence not found', ['geofence_uuid' => 'zone-uuid', 'geofence_type' => 'zone']],
        ]);
});

test('check geofence dwell fires when subject remains inside geofence', function () {
    $job           = new FleetOpsCheckGeofenceDwellProbe('vehicle-uuid', 'zone-uuid', 'zone', 'vehicle');
    $job->state    = (object) ['entered_at' => '2026-03-01 10:00:00'];
    $job->subject  = new Vehicle();
    $job->geofence = new Zone();

    $job->handle();

    expect($job->warnings)->toBe([])
        ->and($job->events)->toBe([[$job->subject, $job->geofence, '2026-03-01 10:00:00']]);
});

test('send position replay builds payload sends to socket and logs failures', function () {
    $job     = new FleetOpsSendPositionReplayProbe('vehicle.vehicle-uuid', fleetOpsReplayPosition(), 7, 'override-subject');
    $payload = $job->payload();

    expect($payload['id'])->toStartWith('event_')
        ->and($payload)->toMatchArray([
            'api_version' => config('api.version'),
            'event'       => 'position.simulated',
            'created_at'  => '2026-03-01 10:15:30',
            'data'        => [
                'id'             => 'override-subject',
                'location'       => ['type' => 'Point', 'coordinates' => [103.8, 1.3]],
                'heading'        => 0,
                'speed'          => 44,
                'altitude'       => 0,
                'additionalData' => [
                    'index'         => 7,
                    'position_uuid' => 'position-uuid',
                ],
            ],
        ]);

    $job->handle();

    expect($job->socket->records)->toHaveCount(1)
        ->and($job->socket->records[0][0])->toBe('vehicle.vehicle-uuid')
        ->and($job->socket->records[0][1]['event'])->toBe('position.simulated')
        ->and($job->socket->records[0][1]['data']['id'])->toBe('override-subject');

    $job->socket->throwable = new RuntimeException('socket offline');
    $job->handle();

    expect($job->errors)->toBe([
        'Failed to send replay event [position-uuid]: socket offline',
    ]);
});

test('replay positions schedules replay jobs using relative offsets and speed floor', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));

    $positions = collect([
        fleetOpsReplayPositionAt('position-1', '2026-03-01 10:00:00'),
        fleetOpsReplayPositionAt('position-2', '2026-03-01 10:00:10'),
        fleetOpsReplayPositionAt('position-3', '2026-03-01 09:59:55'),
    ]);

    $job = new FleetOpsReplayPositionsProbe($positions, 'vehicle.vehicle-uuid', 2, 'subject-uuid');
    $job->handle();

    expect($job->dispatches)->toBe([
        ['position-1', 0, 0.0],
        ['position-2', 1, 5.0],
        ['position-3', 2, 0.0],
    ])
        ->and($job->logs)->toBe(['Replay scheduled for 3 positions on channel vehicle.vehicle-uuid']);

    $slow = new FleetOpsReplayPositionsProbe(collect([
        fleetOpsReplayPositionAt('position-slow-1', '2026-03-01 10:00:00'),
        fleetOpsReplayPositionAt('position-slow-2', '2026-03-01 10:00:01'),
    ]), 'vehicle.vehicle-uuid', 0, null);
    $slow->handle();

    expect($slow->dispatches[1])->toBe(['position-slow-2', 1, 10.0]);

    Carbon::setTestNow();
});

test('notify bulk assigned driver updates orders and records notification failures', function () {
    $missingDriverJob         = new FleetOpsNotifyBulkAssignedDriverProbe(['order-1'], 'missing-driver');
    $missingDriverJob->orders = [new FleetOpsBulkAssignedOrderFake()];
    $missingDriverJob->handle();

    expect($missingDriverJob->orders[0]->notified)->toBeFalse()
        ->and($missingDriverJob->warnings)->toBe([]);

    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-uuid'], true);

    $first = new FleetOpsBulkAssignedOrderFake();
    $first->setRawAttributes(['uuid' => 'order-1'], true);

    $second           = new FleetOpsBulkAssignedOrderFake();
    $second->throwing = new RuntimeException('notification offline');
    $second->setRawAttributes(['uuid' => 'order-2'], true);

    $job         = new FleetOpsNotifyBulkAssignedDriverProbe(['order-1', 'order-2'], 'driver-uuid');
    $job->driver = $driver;
    $job->orders = [$first, $second];

    $job->handle();

    expect($first->notified)->toBeTrue()
        ->and($first->driver_assigned_uuid)->toBe('driver-uuid')
        ->and($first->driverAssigned)->toBe($driver)
        ->and($second->notified)->toBeFalse()
        ->and($second->driver_assigned_uuid)->toBe('driver-uuid')
        ->and($second->driverAssigned)->toBe($driver)
        ->and($job->warnings)->toBe([
            ['order-2', 'notification offline'],
        ]);
});

test('finalize api order creation job prepares optional dispatch and emits ready event', function () {
    $missingOrderJob        = new FleetOpsFinalizeApiOrderCreationProbe('missing-order');
    $missingOrderJob->order = null;
    $missingOrderJob->handle();

    expect($missingOrderJob->events)->toBe([]);

    $order = new FleetOpsFinalizeOrderFake();
    $order->setRawAttributes(['uuid' => 'order-uuid'], true);

    $serviceQuote = new ServiceQuote();
    $serviceQuote->setRawAttributes(['uuid' => 'service-quote-uuid'], true);

    $job               = new FleetOpsFinalizeApiOrderCreationProbe('order-uuid', 'service-quote-uuid', true);
    $job->order        = $order;
    $job->serviceQuote = $serviceQuote;

    $job->handle();

    expect($order->calls)->toBe([
        'notifyDriverAssigned',
        'setPreliminaryDistanceAndTime',
        ['purchaseServiceQuote', 'service-quote-uuid'],
        'dispatchWithActivity',
    ])
        ->and($job->events)->toBe(['order-uuid']);

    $withoutDispatch        = new FleetOpsFinalizeApiOrderCreationProbe('order-uuid', null, false);
    $withoutDispatch->order = new FleetOpsFinalizeOrderFake();
    $withoutDispatch->order->setRawAttributes(['uuid' => 'order-uuid-no-dispatch'], true);
    $withoutDispatch->handle();

    expect($withoutDispatch->order->calls)->toBe([
        'notifyDriverAssigned',
        'setPreliminaryDistanceAndTime',
        ['purchaseServiceQuote', null],
    ])
        ->and($withoutDispatch->events)->toBe(['order-uuid-no-dispatch']);
});

test('finalize internal order creation job notifies driver and emits ready event', function () {
    $missingOrderJob        = new FleetOpsFinalizeInternalOrderCreationProbe('missing-order');
    $missingOrderJob->order = null;
    $missingOrderJob->handle();

    expect($missingOrderJob->events)->toBe([]);

    $order = new FleetOpsFinalizeOrderFake();
    $order->setRawAttributes(['uuid' => 'internal-order-uuid'], true);

    $job        = new FleetOpsFinalizeInternalOrderCreationProbe('internal-order-uuid');
    $job->order = $order;
    $job->handle();

    expect($order->calls)->toBe(['notifyDriverAssigned'])
        ->and($job->events)->toBe(['internal-order-uuid']);
});

test('simulate driving route builds waypoint chain and dispatches the first waypoint', function () {
    $driver = new Driver();
    $driver->setRawAttributes(['uuid' => 'driver-uuid'], true);

    $first  = new Point(1.30, 103.80);
    $second = new Point(1.31, 103.81);
    $third  = new Point(1.32, 103.82);

    $job = new FleetOpsSimulateDrivingRouteProbe($driver, [$first, $second, $third]);
    $job->handle();

    expect($job->made)->toHaveCount(2)
        ->and($job->made[0])->toBe([$second, ['index' => 1]])
        ->and($job->made[1])->toBe([$third, ['index' => 2]])
        ->and($job->dispatched)->toHaveCount(1)
        ->and($job->dispatched[0][0])->toBe($first)
        ->and($job->dispatched[0][1])->toHaveCount(2)
        ->and($job->dispatched[0][1][1])->toBeInstanceOf(SimulateWaypointReached::class)
        ->and($job->dispatched[0][1][2])->toBeInstanceOf(SimulateWaypointReached::class);
});

test('sync fuel provider transactions job passes parsed dates and records failure state', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00'));

    $connection = new FleetOpsFuelProviderConnectionFake();
    $connection->setRawAttributes(['uuid' => 'connection-uuid'], true);
    $syncRun = new FleetOpsFuelProviderSyncRunFake();
    $syncRun->setRawAttributes(['uuid' => 'sync-run-uuid'], true);
    $service = new FleetOpsFuelProviderServiceFake();

    $job                 = new FleetOpsSyncFuelProviderTransactionsJobProbe('connection-uuid', '2026-04-01T00:00:00Z', '2026-04-30T23:59:59Z', ['dry_run' => true], 'sync-run-uuid');
    $job->fakeConnection = $connection;
    $job->syncRun        = $syncRun;

    $job->handle($service);

    expect($service->calls)->toHaveCount(1)
        ->and($service->calls[0][0])->toBe($connection)
        ->and($service->calls[0][1]?->toIso8601String())->toBe('2026-04-01T00:00:00+00:00')
        ->and($service->calls[0][2]?->toIso8601String())->toBe('2026-04-30T23:59:59+00:00')
        ->and($service->calls[0][3])->toBe(['dry_run' => true])
        ->and($service->calls[0][4])->toBe($syncRun);

    $service->throwable = new RuntimeException('provider failed');

    expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'provider failed')
        ->and($syncRun->updates[0])->toMatchArray([
            'status' => 'error',
            'error'  => 'provider failed',
        ])
        ->and($syncRun->updates[0]['finished_at']->toDateTimeString())->toBe('2026-05-01 12:00:00')
        ->and($connection->updates[0])->toMatchArray([
            'status'          => 'error',
            'last_error'      => 'provider failed',
            'last_sync_state' => [
                'failed_at' => '2026-05-01T12:00:00+00:00',
                'message'   => 'provider failed',
            ],
        ]);

    Carbon::setTestNow();
});

test('test telematic connection job records success and marks failures', function () {
    $telematic = new FleetOpsTelematicConnectionFake();
    $telematic->setRawAttributes([
        'uuid'     => 'telematic-uuid',
        'provider' => 'demo-provider',
        'status'   => 'pending',
    ], true);

    $provider = new FleetOpsTelematicProviderFake();
    $registry = new FleetOpsTelematicProviderRegistryFake($provider);
    $service  = new FleetOpsTelematicConnectionServiceFake();
    $job      = new TestTelematicConnectionJob($telematic, 'connection-job-id');

    $job->handle($registry, $service);

    expect($job->getJobId())->toBe('connection-job-id')
        ->and($registry->resolved)->toBe(['demo-provider'])
        ->and($provider->tests)->toBe([['token' => 'secret']])
        ->and($service->records)->toHaveCount(1)
        ->and($service->records[0][0])->toBe($telematic)
        ->and($service->records[0][1])->toMatchArray([
            'success'  => true,
            'message'  => 'Connection verified',
            'metadata' => ['account' => 'demo'],
        ]);

    $failingTelematic = new FleetOpsTelematicConnectionFake();
    $failingTelematic->setRawAttributes([
        'uuid'     => 'telematic-failing-uuid',
        'provider' => 'demo-provider',
        'status'   => 'pending',
    ], true);
    $provider->throwable = new RuntimeException('provider offline');
    $failingJob          = new TestTelematicConnectionJob($failingTelematic);

    expect(fn () => $failingJob->handle($registry, $service))->toThrow(RuntimeException::class, 'provider offline')
        ->and($failingJob->getJobId())->not->toBe('')
        ->and($failingTelematic->status)->toBe('error')
        ->and($failingTelematic->saved)->toBeTrue();
});
