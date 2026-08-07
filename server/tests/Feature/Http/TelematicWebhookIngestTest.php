<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Http\Controllers\TelematicWebhookController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Covers the telematic webhook controller's custom ingest endpoint and the
 * signature-based telematic resolution branch of the provider webhook handler
 * against an in-memory SQLite fixture with service, registry, and idempotency
 * fakes.
 */
class FleetOpsWebhookIngestServiceFake extends TelematicService
{
    public array $linked        = [];
    public array $events        = [];
    public array $sensors       = [];
    public bool $eventThrows    = false;
    public ?Telematic $resolved = null;

    public function __construct()
    {
    }

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        if (empty($deviceData['external_id']) && empty($deviceData['imei'])) {
            throw ValidationException::withMessages(['external_id' => ['required']]);
        }

        $this->linked[] = $deviceData;
        $device         = new Device();
        $device->setRawAttributes(['uuid' => 'device-' . count($this->linked)], true);

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        if ($this->eventThrows) {
            throw new Exception('event storage failed');
        }

        $this->events[] = [$eventData, $device?->uuid];
        $event          = new DeviceEvent();
        $event->setRawAttributes(['uuid' => 'event-' . count($this->events)], true);

        return $event;
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        if (empty($sensorData['name'])) {
            throw ValidationException::withMessages(['name' => ['required']]);
        }

        $this->sensors[] = [$sensorData, $device?->uuid];
        $sensor          = new Sensor();
        $sensor->setRawAttributes(['uuid' => 'sensor-' . count($this->sensors)], true);

        return $sensor;
    }

    public function getCredentials(Telematic $telematic): array
    {
        return ['token' => 'secret'];
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        return $this->resolved;
    }
}

class FleetOpsWebhookIngestIdempotencyFake extends IdempotencyManager
{
    public array $processed   = [];
    public bool $duplicate    = false;

    public function __construct()
    {
    }

    public function isDuplicate(string $key): bool
    {
        return $this->duplicate;
    }

    public function markProcessed(string $key): void
    {
        $this->processed[] = $key;
    }
}

class FleetOpsWebhookIngestProviderStub implements TelematicProviderInterface
{
    public array $validSignatures = [];

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true];
    }

    public function fetchDevices(array $options = []): array
    {
        return [];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return [];
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
        return in_array($signature, $this->validSignatures, true);
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
        return false;
    }

    public function getRateLimits(): array
    {
        return [];
    }
}

class FleetOpsWebhookIngestRegistryFake extends TelematicProviderRegistry
{
    public ?TelematicProviderInterface $provider = null;

    public function resolve(string $key): TelematicProviderInterface
    {
        return $this->provider ?? parent::resolve($key);
    }
}

function fleetopsWebhookIngestBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $schema->create('telematics', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'public_id', 'company_uuid', 'provider', 'meta'] as $column) {
            $table->string($column)->nullable();
        }
        $table->timestamps();
        $table->timestamp('deleted_at')->nullable();
    });

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsWebhookIngestController(
    ?FleetOpsWebhookIngestServiceFake $service = null,
    ?FleetOpsWebhookIngestIdempotencyFake $idempotency = null,
    ?TelematicProviderRegistry $registry = null,
): TelematicWebhookController {
    return new TelematicWebhookController(
        $registry ?? new TelematicProviderRegistry(),
        $service ?? new FleetOpsWebhookIngestServiceFake(),
        $idempotency ?? new FleetOpsWebhookIngestIdempotencyFake()
    );
}

test('ingest throws for unknown telematic identifiers', function () {
    fleetopsWebhookIngestBoot();

    expect(fn () => fleetopsWebhookIngestController()->ingest(Request::create('/x', 'POST'), 'missing'))
        ->toThrow(ModelNotFoundException::class);
});

test('ingest short circuits duplicate idempotency keys', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    $idempotency            = new FleetOpsWebhookIngestIdempotencyFake();
    $idempotency->duplicate = true;

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-Idempotency-Key', 'idem-1');
    $response = fleetopsWebhookIngestController(null, $idempotency)->ingest($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'duplicate']);
});

test('ingest links devices stores events and sensors and marks processed', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    $service     = new FleetOpsWebhookIngestServiceFake();
    $idempotency = new FleetOpsWebhookIngestIdempotencyFake();

    $request = Request::create('/x', 'POST', [
        'devices' => [
            ['external_id' => 'ext-1', 'name' => 'Tracker'],
            ['name' => 'No identity device'],
        ],
        'events' => [
            ['device_id' => 'ext-1', 'type' => 'location'],
            ['type' => 'orphan-event'],
        ],
        'sensors' => [
            ['device_id' => 'ext-1', 'name' => 'Temp'],
            ['device_id' => 'ext-1'],
        ],
    ]);
    $request->headers->set('X-Idempotency-Key', 'idem-2');

    $response = fleetopsWebhookIngestController($service, $idempotency)->ingest($request, 'telematic-1');

    expect($response->getData(true))->toBe(['status' => 'ingested'])
        ->and($service->linked)->toHaveCount(1)
        ->and($service->events)->toHaveCount(2)
        ->and($service->events[0][1])->toBe('device-1')
        ->and($service->events[1][1])->toBeNull()
        ->and($service->sensors)->toHaveCount(1)
        ->and($idempotency->processed)->toBe(['idem-2']);
});

test('ingest reports processing failures', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'custom']);

    $service              = new FleetOpsWebhookIngestServiceFake();
    $service->eventThrows = true;

    $request  = Request::create('/x', 'POST', ['events' => [['type' => 'boom']]]);
    $response = fleetopsWebhookIngestController($service)->ingest($request, 'telematic_test');

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['error' => 'Ingest failed']);
});

test('handle resolves the telematic by unique signature match', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert(['uuid' => 'telematic-1', 'public_id' => 'telematic_test', 'company_uuid' => 'company-1', 'provider' => 'stub']);

    $provider                  = new FleetOpsWebhookIngestProviderStub();
    $provider->validSignatures = ['sig-valid'];
    $registry                  = new FleetOpsWebhookIngestRegistryFake();
    $registry->provider        = $provider;

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-Webhook-Signature', 'sig-valid');

    $response = fleetopsWebhookIngestController(null, null, $registry)->handle($request, 'stub');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'processed']);
});

test('handle rejects ambiguous signature matches', function () {
    $connection = fleetopsWebhookIngestBoot();
    $connection->table('telematics')->insert([
        ['uuid' => 'telematic-1', 'public_id' => 'telematic_a', 'company_uuid' => 'company-1', 'provider' => 'stub', 'meta' => null],
        ['uuid' => 'telematic-2', 'public_id' => 'telematic_b', 'company_uuid' => 'company-1', 'provider' => 'stub', 'meta' => null],
    ]);

    $provider                  = new FleetOpsWebhookIngestProviderStub();
    $provider->validSignatures = ['sig-shared'];
    $registry                  = new FleetOpsWebhookIngestRegistryFake();
    $registry->provider        = $provider;

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-Webhook-Signature', 'sig-shared');

    $response = fleetopsWebhookIngestController(null, null, $registry)->handle($request, 'stub');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toBe(['error' => 'Ambiguous telematic integration']);
});
