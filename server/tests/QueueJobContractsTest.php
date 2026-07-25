<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Jobs\SendPositionReplay;
use Fleetbase\FleetOps\Jobs\SyncFuelProviderTransactionsJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderSyncRun;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
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
