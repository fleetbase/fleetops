<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

if (!function_exists('Fleetbase\FleetOps\Support\Telematics\dispatch')) {
    eval('namespace Fleetbase\FleetOps\Support\Telematics; function dispatch($job) { return \app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job); }');
}

class FleetOpsTelematicLifecycleFake extends Telematic
{
    public int $saveCount    = 0;
    public int $deleteCount  = 0;
    public array $savedState = [];

    public function save(array $options = [])
    {
        $this->saveCount++;
        $this->exists       = true;
        $this->savedState[] = $this->getAttributes();

        return true;
    }

    public function delete()
    {
        $this->deleteCount++;

        return true;
    }
}

class FleetOpsTelematicLifecycleDeviceFake extends Device
{
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }
}

class FleetOpsTelematicLifecycleProvider implements TelematicProviderInterface
{
    public array $connectedTelematics           = [];
    public array $testedCredentials             = [];
    public array $testResult                    = ['success' => true, 'message' => 'Connected', 'metadata' => ['latency_ms' => 12]];
    public ?Throwable $normalizeEventsException = null;

    public function connect(Telematic $telematic): void
    {
        $this->connectedTelematics[] = $telematic;
    }

    public function testConnection(array $credentials): array
    {
        $this->testedCredentials[] = $credentials;

        return $this->testResult;
    }

    public function fetchDevices(array $options = []): array
    {
        return ['devices' => [], 'next_cursor' => null, 'has_more' => false, 'options' => $options];
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

    public function normalizeEvents(array $payload): array
    {
        if ($this->normalizeEventsException) {
            throw $this->normalizeEventsException;
        }

        return $payload['events'] ?? [];
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
        return true;
    }

    public function supportsDiscovery(): bool
    {
        return true;
    }

    public function getRateLimits(): array
    {
        return ['requests_per_minute' => 60, 'burst_size' => 10];
    }
}

class FleetOpsTelematicLifecycleRegistry extends TelematicProviderRegistry
{
    public ?TelematicProviderDescriptor $descriptor = null;
    public FleetOpsTelematicLifecycleProvider $provider;

    public function __construct()
    {
        $this->provider   = new FleetOpsTelematicLifecycleProvider();
        $this->descriptor = new TelematicProviderDescriptor([
            'key'             => 'unit-provider',
            'label'           => 'Unit Provider',
            'required_fields' => [
                ['name' => 'token', 'required' => true, 'validation' => 'string'],
            ],
        ]);
    }

    public function findByKey(string $key): ?TelematicProviderDescriptor
    {
        return $key === $this->descriptor?->key ? $this->descriptor : null;
    }

    public function resolve(string $key): TelematicProviderInterface
    {
        if ($key !== $this->descriptor?->key) {
            throw new InvalidArgumentException("Provider '{$key}' not found in registry.");
        }

        return $this->provider;
    }
}

class FleetOpsTelematicLifecycleService extends TelematicService
{
    protected function validateCredentials(array $credentials, array $schema): void
    {
        foreach ($schema as $field) {
            if (($field['required'] ?? true) && empty($credentials[$field['name']])) {
                throw ValidationException::withMessages([$field['name'] => ['The ' . $field['name'] . ' field is required.']]);
            }
        }
    }

    protected function encryptCredentials(array $credentials): string
    {
        return json_encode($credentials);
    }
}

class FleetOpsTelematicLifecycleIngestService extends TelematicService
{
    public array $linkedDevices = [];
    public array $storedEvents  = [];
    public int $storedSensors   = 0;

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        $this->linkedDevices[] = [$telematic, $deviceData];

        $device = new FleetOpsTelematicLifecycleDeviceFake([
            'uuid'      => 'device-uuid',
            'device_id' => $deviceData['device_id'] ?? $deviceData['external_id'] ?? 'device-external',
            'status'    => 'active',
            'meta'      => [],
        ]);
        $device->exists = true;

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        $this->storedEvents[] = [$telematic, $eventData, $device];

        $event = new DeviceEvent();
        $event->setRawAttributes([
            'uuid'       => 'event-' . count($this->storedEvents),
            'event_type' => $eventData['event_type'] ?? $eventData['type'] ?? 'telemetry_update',
        ], true);
        $event->exists = true;

        return $event;
    }

    protected function storeSnapshotSensors(Telematic $telematic, TelematicProviderInterface $provider, array $payload, Device $device): int
    {
        return $this->storedSensors;
    }
}

class FleetOpsTelematicLifecycleDispatcher implements Dispatcher
{
    public array $commands = [];

    public function dispatch($command)
    {
        $this->commands[] = $command;

        return $command;
    }

    public function dispatchSync($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null)
    {
        return $this->dispatch($command);
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command)
    {
        return null;
    }

    public function pipeThrough(array $pipes)
    {
        return $this;
    }

    public function map(array $map)
    {
        return $this;
    }
}

function fleetopsTelematicLifecycleService(?FleetOpsTelematicLifecycleRegistry $registry = null): FleetOpsTelematicLifecycleService
{
    return new FleetOpsTelematicLifecycleService($registry ?? new FleetOpsTelematicLifecycleRegistry());
}

function fleetOpsTelematicLifecycleInvoke(TelematicService $service, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $arguments);
}

function fleetopsTelematicLifecycleUseInMemoryConnection(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->statement('create table telematics (id integer primary key autoincrement, uuid varchar(64) null, public_id varchar(64) null, company_uuid varchar(64) null, name varchar(255) null, provider varchar(255) null, credentials text null, status varchar(64) null, meta text null, created_at datetime null, updated_at datetime null)');

    $resolver = new ConnectionResolver([
        'default' => $connection,
        'mysql'   => $connection,
    ]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);

    return $connection;
}

test('telematic service creates updates deletes and records connection state', function () {
    fleetopsTelematicLifecycleUseInMemoryConnection();
    Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00'));
    session(['company' => 'company-telematics']);

    $registry = new FleetOpsTelematicLifecycleRegistry();
    $service  = fleetopsTelematicLifecycleService($registry);

    $telematic = $service->create([
        'name'            => 'Primary Telematics',
        'provider_key'    => 'unit-provider',
        'credentials'     => ['token' => 'secret-token'],
        'test_connection' => true,
        'meta'            => ['region' => 'sg'],
    ]);

    expect($telematic)->toBeInstanceOf(Telematic::class)
        ->and($telematic->company_uuid)->toBe('company-telematics')
        ->and($telematic->name)->toBe('Primary Telematics')
        ->and($telematic->provider)->toBe('unit-provider')
        ->and($telematic->credentials)->toBe(json_encode(['token' => 'secret-token']))
        ->and($telematic->status)->toBe('active')
        ->and($telematic->meta)->toBe(['region' => 'sg'])
        ->and($registry->provider->testedCredentials)->toBe([['token' => 'secret-token']]);

    $fake = new FleetOpsTelematicLifecycleFake();
    $fake->setRawAttributes([
        'uuid'        => 'telematic-uuid',
        'provider'    => 'unit-provider',
        'credentials' => json_encode(['token' => 'old-token']),
        'status'      => 'active',
        'meta'        => ['existing' => 'kept'],
    ], true);

    $updated = $service->update($fake, [
        'name'        => 'Updated Telematics',
        'credentials' => ['token' => 'new-token'],
        'status'      => 'paused',
        'meta'        => ['region' => 'id'],
    ]);

    expect($updated)->toBe($fake)
        ->and($fake->name)->toBe('Updated Telematics')
        ->and($fake->credentials)->toBe(json_encode(['token' => 'new-token']))
        ->and($fake->status)->toBe('paused')
        ->and($fake->meta)->toBe(['existing' => 'kept', 'region' => 'id'])
        ->and($fake->saveCount)->toBe(1)
        ->and($service->delete($fake))->toBeTrue()
        ->and($fake->deleteCount)->toBe(1);

    $service->recordConnectionTest($fake, [
        'success'  => false,
        'message'  => 'Invalid token',
        'metadata' => ['status' => 401],
    ]);

    expect($fake->status)->toBe('error')
        ->and($fake->meta)->toMatchArray([
            'existing'             => 'kept',
            'region'               => 'id',
            'last_connection_test' => '2026-07-24 10:00:00',
            'last_test_result'     => 'failed',
            'last_error'           => 'Invalid token',
            'last_test_metadata'   => ['status' => 401],
        ]);

    Carbon::setTestNow();
});

test('telematic service validates provider and connection failures', function () {
    $registry = new FleetOpsTelematicLifecycleRegistry();
    $service  = fleetopsTelematicLifecycleService($registry);

    expect(fn () => $service->create([
        'name'         => 'Missing Provider',
        'provider_key' => 'missing-provider',
        'credentials'  => ['token' => 'secret-token'],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->create([
        'name'         => 'Missing Token',
        'provider_key' => 'unit-provider',
        'credentials'  => [],
    ]))->toThrow(ValidationException::class);

    $registry->provider->testResult = ['success' => false, 'message' => 'Nope', 'metadata' => []];

    expect(fn () => $service->create([
        'name'            => 'Bad Connection',
        'provider_key'    => 'unit-provider',
        'credentials'     => ['token' => 'secret-token'],
        'test_connection' => true,
    ]))->toThrow(ValidationException::class);
});

test('telematic service tests connections discovers devices and decodes credentials', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-24 11:00:00'));

    $dispatcher = new FleetOpsTelematicLifecycleDispatcher();
    app()->instance(Dispatcher::class, $dispatcher);

    $registry = new FleetOpsTelematicLifecycleRegistry();
    $service  = fleetopsTelematicLifecycleService($registry);

    $telematic = new FleetOpsTelematicLifecycleFake();
    $telematic->setRawAttributes([
        'uuid'        => 'telematic-uuid',
        'provider'    => 'unit-provider',
        'credentials' => json_encode(['token' => 'json-token']),
        'status'      => 'active',
        'meta'        => ['existing' => 'kept'],
    ], true);

    $result = $service->testConnection($telematic);

    expect($result)->toBe(['success' => true, 'message' => 'Connected', 'metadata' => ['latency_ms' => 12]])
        ->and($registry->provider->connectedTelematics)->toBe([$telematic])
        ->and($registry->provider->testedCredentials)->toBe([['token' => 'json-token']])
        ->and($telematic->status)->toBe('connected')
        ->and($telematic->meta)->toMatchArray([
            'existing'             => 'kept',
            'last_connection_test' => '2026-07-24 11:00:00',
            'last_test_result'     => 'success',
            'last_error'           => null,
            'last_test_metadata'   => ['latency_ms' => 12],
        ]);

    Str::createUuidsUsingSequence([
        '11111111-1111-4111-8111-111111111111',
    ]);

    $jobId = $service->discoverDevices($telematic, ['limit' => 50]);

    expect($jobId)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0])->toBeInstanceOf(SyncTelematicDevicesJob::class)
        ->and($telematic->status)->toBe('synchronizing')
        ->and($telematic->meta)->toMatchArray([
            'existing'             => 'kept',
            'last_sync_job_id'     => '11111111-1111-4111-8111-111111111111',
            'last_sync_started_at' => '2026-07-24 11:00:00',
            'last_sync_result'     => 'queued',
            'last_sync_error'      => null,
        ])
        ->and($service->getCredentials(new Telematic(['credentials' => ['token' => 'array-token']])))->toBe(['token' => 'array-token'])
        ->and($service->getCredentials(new Telematic(['credentials' => null])))->toBe([])
        ->and($service->getCredentials(new Telematic(['credentials' => json_encode(['token' => 'fallback-token'])])))->toBe(['token' => 'fallback-token']);

    Str::createUuidsNormally();
    Carbon::setTestNow();
});

test('telematic service queues async connection tests', function () {
    $dispatcher = new FleetOpsTelematicLifecycleDispatcher();
    app()->instance(Dispatcher::class, $dispatcher);

    Str::createUuidsUsingSequence([
        '22222222-2222-4222-8222-222222222222',
    ]);

    $service   = fleetopsTelematicLifecycleService();
    $telematic = new FleetOpsTelematicLifecycleFake();
    $telematic->setRawAttributes([
        'uuid'     => 'telematic-uuid',
        'provider' => 'unit-provider',
    ], true);

    expect($service->testConnection($telematic, true))->toBe([
        'job_id'  => '22222222-2222-4222-8222-222222222222',
        'message' => 'Connection test queued',
    ])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0])->toBeInstanceOf(TestTelematicConnectionJob::class);

    Str::createUuidsNormally();
});

test('telematic service rejects missing device and sensor identities', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-24 12:30:00'));
    $service = new TelematicService(new FleetOpsTelematicLifecycleRegistry());

    $telematic = new FleetOpsTelematicLifecycleFake();
    $telematic->setRawAttributes([
        'uuid'         => 'telematic-uuid',
        'public_id'    => 'telematic_public',
        'company_uuid' => 'company-uuid',
        'provider'     => 'unit-provider',
    ], true);

    expect(fn () => $service->linkDevice($telematic, ['name' => 'Missing identity']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->storeSensor($telematic, ['name' => 'Missing identity']))
        ->toThrow(ValidationException::class);

    $device         = new FleetOpsTelematicLifecycleDeviceFake([
        'status' => 'active',
        'meta'   => [],
    ]);
    $device->exists = true;

    fleetOpsTelematicLifecycleInvoke($service, 'reconcileDeviceTelemetry', [$device, $telematic, [
        'device_id' => 'online-without-timestamp',
        'online'    => true,
    ]]);

    expect($device->last_online_at?->toDateTimeString())->toBe('2026-07-24 12:30:00')
        ->and($device->online)->toBeTrue()
        ->and($device->status)->toBe('online')
        ->and($device->last_position)->toBe(['latitude' => 0, 'longitude' => 0]);

    Carbon::setTestNow();
});

test('telematic service ingests snapshots with event signals and handles provider event failures', function () {
    $provider               = new FleetOpsTelematicLifecycleProvider();
    $service                = new FleetOpsTelematicLifecycleIngestService(new FleetOpsTelematicLifecycleRegistry());
    $service->storedSensors = 2;

    $telematic = new FleetOpsTelematicLifecycleFake();
    $telematic->setRawAttributes([
        'uuid'         => 'telematic-uuid',
        'public_id'    => 'telematic_public',
        'company_uuid' => 'company-uuid',
        'provider'     => 'unit-provider',
    ], true);

    $snapshot = $service->ingestDeviceSnapshot($telematic, $provider, [
        'device_id' => 'device-external',
        'events'    => [
            [],
            ['event_id'  => 'evt-1', 'event_type' => 'ignition_on'],
            ['timestamp' => '2026-07-24 12:45:00', 'speed' => 12],
        ],
    ]);

    expect($service->linkedDevices)->toHaveCount(1)
        ->and($service->linkedDevices[0][1])->toMatchArray(['device_id' => 'device-external'])
        ->and($service->storedEvents)->toHaveCount(2)
        ->and($service->storedEvents[0][1]['event_id'])->toBe('evt-1')
        ->and($service->storedEvents[1][1]['timestamp'])->toBe('2026-07-24 12:45:00')
        ->and($snapshot['device'])->toBeInstanceOf(Device::class)
        ->and($snapshot['event'])->toBe($snapshot['events'][0])
        ->and($snapshot['events'])->toHaveCount(2)
        ->and($snapshot['sensors'])->toBe(2);

    $provider->normalizeEventsException = new RuntimeException('provider normalization failed');
    $failedSnapshot                     = $service->ingestDeviceSnapshot($telematic, $provider, [
        'device_id' => 'device-external',
        'events'    => [['event_id' => 'evt-2']],
    ]);

    expect($failedSnapshot['event'])->toBeNull()
        ->and($failedSnapshot['events'])->toBe([])
        ->and($failedSnapshot['sensors'])->toBe(2);
});

test('telematic service normalizes helper branch inputs', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-24 13:00:00'));

    $service = new TelematicService(new FleetOpsTelematicLifecycleRegistry());
    $device  = new FleetOpsTelematicLifecycleDeviceFake([
        'uuid'           => 'device-uuid',
        'device_id'      => 'device-external',
        'last_online_at' => Carbon::parse('2026-07-24 12:30:00'),
        'status'         => 'maintenance',
        'meta'           => [],
    ]);
    $telematic = new FleetOpsTelematicLifecycleFake();
    $telematic->setRawAttributes([
        'uuid'      => 'telematic-uuid',
        'public_id' => 'telematic_public',
        'provider'  => 'unit-provider',
    ], true);

    expect(fleetOpsTelematicLifecycleInvoke($service, 'normalizeLocation', [['lat' => '1.25', 'lng' => '103.75']]))
        ->toBe(['latitude' => 1.25, 'longitude' => 103.75])
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'normalizeLocation', [['lat' => '1.25']]))->toBeNull()
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveTelemetryTimestamp', [['timestamp' => 'not-a-date']]))->toBeNull()
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveTelemetryTimestamp', [['meta' => ['last_update' => ['occurred_at' => '2026-07-24 12:59:00']]]])?->toDateTimeString())->toBe('2026-07-24 12:59:00')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveReportedOnline', [['online' => 'definitely']]))->toBeTrue()
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-24 12:30:00'), null]))->toBe('recently_offline')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'connectionStatusForDevice', [$device, Carbon::parse('2026-07-23 12:00:00'), null]))->toBe('long_offline')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'isProtectedDeviceStatus', ['maintenance']))->toBeTrue()
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveExternalId', [['unit_id' => 1234]]))->toBe('1234')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveProviderAccountId', [[], ['x-customer-id' => ['customer-from-header']]]))->toBe('customer-from-header')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'resolveProviderAccountId', [['organization' => ['id' => 'org-from-payload']], []]))->toBe('org-from-payload')
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'makeEventKey', [$telematic, ['event_id' => 'evt-without-device'], null]))->toBeNull()
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'makeEventKey', [$telematic, ['timestamp' => '2026-07-24 12:59:00'], $device]))->toBe(sha1('unit-provider|telematic_public|device-external||telemetry_update|2026-07-24 12:59:00'))
        ->and(fleetOpsTelematicLifecycleInvoke($service, 'makeSensorIdentity', [['sensor_type' => 'temperature', 'unit' => 'celsius'], $device]))->toBe(sha1('device-uuid|temperature|celsius'));

    fleetOpsTelematicLifecycleInvoke($service, 'setDeviceAttributeIfPresent', [$device, 'name', '']);
    expect($device->name)->toBeNull();

    fleetOpsTelematicLifecycleInvoke($service, 'setDeviceAttributeIfPresent', [$device, 'name', 'Tracker A']);
    expect($device->name)->toBe('Tracker A');

    Carbon::setTestNow();
});

test('real credential validation builds rules and encryption round trips', function () {
    fleetopsTelematicLifecycleUseInMemoryConnection();
    $service = new TelematicService(new FleetOpsTelematicLifecycleRegistry());

    // A passing validator lets the real rule builder complete
    app()->instance('validator', new class {
        public function make($data = [], $rules = [], $messages = [], $attributes = [])
        {
            $GLOBALS['fleetopsTelematicRules'] = $rules;

            return new class {
                public function fails()
                {
                    return false;
                }

                public function errors()
                {
                    return new Illuminate\Support\MessageBag();
                }
            };
        }
    });
    Illuminate\Support\Facades\Validator::clearResolvedInstance('validator');

    $validate = new ReflectionMethod(TelematicService::class, 'validateCredentials');
    $validate->setAccessible(true);
    $validate->invoke($service, ['token' => 'abc', 'region' => 'sg'], [
        ['name' => 'token', 'required' => true],
        ['name' => 'region', 'required' => false, 'validation' => 'string|max:5'],
    ]);

    expect($GLOBALS['fleetopsTelematicRules']['token'])->toBe('required|string')
        ->and($GLOBALS['fleetopsTelematicRules']['region'])->toBe('string|max:5');

    // Encryption delegates to the encrypter seam
    Illuminate\Support\Facades\Crypt::swap(new class {
        public function encryptString($value)
        {
            return 'encrypted:' . $value;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $encrypt = new ReflectionMethod(TelematicService::class, 'encryptCredentials');
    $encrypt->setAccessible(true);
    expect($encrypt->invoke($service, ['token' => 'abc']))->toContain('encrypted:');
});
