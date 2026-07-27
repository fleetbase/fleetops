<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Jobs\SyncTelematicDevicesJob;
use Fleetbase\FleetOps\Jobs\TestTelematicConnectionJob;
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

class FleetOpsTelematicLifecycleProvider implements TelematicProviderInterface
{
    public array $connectedTelematics = [];
    public array $testedCredentials   = [];
    public array $testResult          = ['success' => true, 'message' => 'Connected', 'metadata' => ['latency_ms' => 12]];

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
