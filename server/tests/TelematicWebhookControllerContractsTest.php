<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Http\Controllers\TelematicWebhookController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Support\IdempotencyManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetOpsWebhookProvider implements TelematicProviderInterface
{
    public bool $signatureValid = true;
    public array $webhookResult = ['devices' => [], 'events' => [], 'sensors' => []];

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true, 'message' => 'ok', 'metadata' => []];
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
        return $this->signatureValid;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        return $this->webhookResult;
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

class FleetOpsWebhookRegistry extends TelematicProviderRegistry
{
    public function __construct(public FleetOpsWebhookProvider $provider)
    {
    }

    public function resolve(string $key): TelematicProviderInterface
    {
        return $this->provider;
    }
}

class FleetOpsWebhookService extends TelematicService
{
    public ?Telematic $telematic = null;
    public array $credentials    = ['secret' => 'webhook-secret'];
    public array $linkedDevices  = [];
    public array $storedEvents   = [];
    public array $storedSensors  = [];
    public bool $rejectDevices   = false;
    public bool $rejectSensors   = false;

    public function __construct()
    {
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        return $this->telematic;
    }

    public function getCredentials(Telematic $telematic): array
    {
        return $this->credentials;
    }

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        if ($this->rejectDevices) {
            throw ValidationException::withMessages(['device_id' => ['missing']]);
        }

        $device = new Device();
        $device->setRawAttributes([
            'uuid'        => 'device-' . count($this->linkedDevices),
            'external_id' => $deviceData['external_id'] ?? null,
        ], true);

        $this->linkedDevices[] = $deviceData;

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): Fleetbase\FleetOps\Models\DeviceEvent
    {
        $this->storedEvents[] = [$eventData, $device?->uuid];

        return new Fleetbase\FleetOps\Models\DeviceEvent();
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Fleetbase\FleetOps\Models\Sensor
    {
        if ($this->rejectSensors) {
            throw ValidationException::withMessages(['sensor_id' => ['missing']]);
        }

        $this->storedSensors[] = [$sensorData, $device?->uuid];

        return new Fleetbase\FleetOps\Models\Sensor();
    }
}

class FleetOpsWebhookIdempotency extends IdempotencyManager
{
    public array $processed = [];

    public function __construct(public bool $duplicate = false)
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

function fleetopsWebhookController(?FleetOpsWebhookProvider $provider = null, ?FleetOpsWebhookService $service = null, ?FleetOpsWebhookIdempotency $idempotency = null): array
{
    $provider ??= new FleetOpsWebhookProvider();
    $service ??= new FleetOpsWebhookService();
    $idempotency ??= new FleetOpsWebhookIdempotency();

    return [
        new TelematicWebhookController(new FleetOpsWebhookRegistry($provider), $service, $idempotency),
        $provider,
        $service,
        $idempotency,
    ];
}

function fleetopsWebhookRequest(array $payload = [], array $headers = [], array $query = []): Request
{
    $request = Request::create('/webhooks/telematics/provider?' . http_build_query($query), 'POST', $payload);
    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return $request;
}

test('telematic webhook controller short circuits duplicate provider webhooks', function () {
    [$controller] = fleetopsWebhookController(idempotency: new FleetOpsWebhookIdempotency(true));

    $response = $controller->handle(fleetopsWebhookRequest(headers: ['X-Idempotency-Key' => 'duplicate-key']), 'samsara');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'duplicate']);
});

test('telematic webhook controller rejects unresolved provider webhooks', function () {
    [$controller] = fleetopsWebhookController();

    $response = $controller->handle(fleetopsWebhookRequest(query: ['telematic' => 'missing']), 'samsara');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true))->toBe(['error' => 'Unable to resolve telematic integration']);
});

test('telematic webhook controller rejects invalid signatures', function () {
    [$controller, $provider, $service] = fleetopsWebhookController();
    $provider->signatureValid          = false;
    $service->telematic                = new Telematic(['uuid' => 'telematic-uuid', 'provider' => 'samsara']);

    $response = $controller->handle(fleetopsWebhookRequest(headers: ['X-Webhook-Signature' => 'bad-signature']), 'samsara');

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe(['error' => 'Invalid signature']);
});

test('telematic webhook controller processes provider devices events and sensors', function () {
    [$controller, $provider, $service, $idempotency] = fleetopsWebhookController();
    $service->telematic                              = new Telematic(['uuid' => 'telematic-uuid', 'provider' => 'samsara']);
    $provider->webhookResult                         = [
        'devices' => [
            ['external_id' => 'device-1'],
            ['name' => 'missing external id'],
        ],
        'events' => [
            ['external_id' => 'device-1', 'type' => 'ignition_on'],
        ],
        'sensors' => [
            ['external_id' => 'device-1', 'type' => 'temperature'],
        ],
    ];

    $response = $controller->handle(fleetopsWebhookRequest(headers: ['X-Idempotency-Key' => 'new-key']), 'samsara');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'processed'])
        ->and($service->linkedDevices)->toHaveCount(2)
        ->and($service->storedEvents)->toHaveCount(1)
        ->and($service->storedEvents[0][1])->toBe('device-0')
        ->and($service->storedSensors)->toHaveCount(1)
        ->and($service->storedSensors[0][1])->toBe('device-0')
        ->and($idempotency->processed)->toBe(['new-key']);
});
