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
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetOpsWebhookProcessingProviderFake implements TelematicProviderInterface
{
    public array $webhookResult         = ['devices' => [], 'events' => [], 'sensors' => []];
    public bool $throwsDuringProcessing = false;

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
        return true;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        if ($this->throwsDuringProcessing) {
            throw new RuntimeException('provider parser failed');
        }

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

class FleetOpsWebhookProcessingRegistryFake extends TelematicProviderRegistry
{
    public function __construct(private FleetOpsWebhookProcessingProviderFake $provider)
    {
    }

    public function resolve(string $key): TelematicProviderInterface
    {
        return $this->provider;
    }
}

class FleetOpsWebhookProcessingServiceFake extends TelematicService
{
    public Telematic $telematic;
    public array $linkedDevices = [];
    public array $storedEvents  = [];
    public array $storedSensors = [];
    public bool $rejectDevices  = false;
    public bool $rejectSensors  = false;

    public function __construct()
    {
        $this->telematic = new Telematic(['uuid' => 'telematic-uuid', 'provider' => 'samsara']);
    }

    public function resolveWebhookTelematic(string $providerKey, array $payload = [], array $headers = [], ?string $integrationId = null): ?Telematic
    {
        return $this->telematic;
    }

    public function getCredentials(Telematic $telematic): array
    {
        return ['secret' => 'webhook-secret'];
    }

    public function linkDevice(Telematic $telematic, array $deviceData): Device
    {
        if ($this->rejectDevices) {
            throw ValidationException::withMessages(['external_id' => ['required']]);
        }

        $externalId = $deviceData['external_id'] ?? $deviceData['device_id'] ?? $deviceData['unit_id'] ?? $deviceData['vehicle_id'] ?? $deviceData['imei'] ?? null;
        $device     = new Device();
        $device->setRawAttributes([
            'uuid'        => 'device-' . count($this->linkedDevices),
            'external_id' => $externalId,
        ], true);

        $this->linkedDevices[] = $deviceData;

        return $device;
    }

    public function storeDeviceEvent(Telematic $telematic, array $eventData, ?Device $device = null): DeviceEvent
    {
        $this->storedEvents[] = [$eventData, $device?->uuid];

        return new DeviceEvent();
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        if ($this->rejectSensors) {
            throw ValidationException::withMessages(['sensor_id' => ['required']]);
        }

        $this->storedSensors[] = [$sensorData, $device?->uuid];

        return new Sensor();
    }
}

class FleetOpsWebhookProcessingIdempotencyFake extends IdempotencyManager
{
    public array $processed = [];

    public function __construct()
    {
    }

    public function isDuplicate(string $key): bool
    {
        return false;
    }

    public function markProcessed(string $key): void
    {
        $this->processed[] = $key;
    }
}

function fleetopsWebhookProcessingController(): array
{
    $provider    = new FleetOpsWebhookProcessingProviderFake();
    $service     = new FleetOpsWebhookProcessingServiceFake();
    $idempotency = new FleetOpsWebhookProcessingIdempotencyFake();

    return [
        new TelematicWebhookController(new FleetOpsWebhookProcessingRegistryFake($provider), $service, $idempotency),
        $provider,
        $service,
        $idempotency,
    ];
}

test('provider webhooks continue when malformed devices and sensors are skipped', function () {
    [$controller, $provider, $service, $idempotency] = fleetopsWebhookProcessingController();
    $provider->webhookResult                         = [
        'devices' => [
            ['unit_id' => 'unit-1'],
        ],
        'events' => [
            ['unit_id' => 'unit-1', 'type' => 'ignition_on'],
            ['imei' => 'unknown-imei', 'type' => 'location'],
            ['type' => 'orphaned-event'],
        ],
        'sensors' => [
            ['unit_id' => 'unit-1', 'name' => 'engine_temp'],
            ['type' => 'orphaned-sensor'],
        ],
    ];

    $response = $controller->handle(
        Request::create('/webhooks/telematics/samsara', 'POST', ['payload' => true], server: ['HTTP_X_IDEMPOTENCY_KEY' => 'skip-key']),
        'samsara'
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'processed'])
        ->and($service->linkedDevices)->toBe([['unit_id' => 'unit-1']])
        ->and($service->storedEvents)->toHaveCount(3)
        ->and($service->storedEvents[0][1])->toBe('device-0')
        ->and($service->storedEvents[1][1])->toBeNull()
        ->and($service->storedEvents[2][1])->toBeNull()
        ->and($service->storedSensors)->toHaveCount(2)
        ->and($service->storedSensors[0][1])->toBe('device-0')
        ->and($service->storedSensors[1][1])->toBeNull()
        ->and($idempotency->processed)->toBe(['skip-key']);

    $service->rejectDevices  = true;
    $service->rejectSensors  = true;
    $provider->webhookResult = [
        'devices' => [
            ['external_id' => 'bad-device'],
        ],
        'sensors' => [
            ['external_id' => 'bad-sensor'],
        ],
    ];

    $response = $controller->handle(Request::create('/webhooks/telematics/samsara', 'POST'), 'samsara');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['status' => 'processed']);
});

test('provider webhook processing failures return server errors', function () {
    [$controller, $provider]          = fleetopsWebhookProcessingController();
    $provider->throwsDuringProcessing = true;

    $response = $controller->handle(Request::create('/webhooks/telematics/samsara', 'POST'), 'samsara');

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true))->toBe(['error' => 'Processing failed']);
});
