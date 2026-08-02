<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\DeviceEvent;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Models\Sensor;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\LaravelMysqlSpatial\Types\Point as SpatialPoint;
use Illuminate\Support\Carbon;

if (!function_exists('Fleetbase\\FleetOps\\Support\\Telematics\\broadcast')) {
    eval('namespace Fleetbase\\FleetOps\\Support\\Telematics; function broadcast($event) { $GLOBALS["fleetops_telematic_service_broadcasts"][] = $event; return $event; }');
}

class FleetOpsTelematicServiceTelemetryDeviceFake extends Device
{
    public int $saveCount          = 0;
    public array $loadMissingCalls = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        return $this->attributes[$key] ?? null;
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function save(array $options = [])
    {
        $this->saveCount++;
        $this->exists = true;

        return true;
    }

    public function loadMissing($relations)
    {
        $this->loadMissingCalls[] = $relations;

        return $this;
    }
}

class FleetOpsTelematicServiceTelemetryEventFake extends DeviceEvent
{
    public array $positions = [];

    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    public function createPosition(array $positionData = []): ?Position
    {
        $this->positions[] = $positionData;

        return null;
    }
}

class FleetOpsTelematicServiceTelemetryVehicleFake extends Vehicle
{
    public int $saveCount = 0;

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

    public function save(array $options = [])
    {
        $this->saveCount++;
        $this->exists = true;

        return true;
    }
}

class FleetOpsTelematicServiceTelemetryProviderFake implements TelematicProviderInterface
{
    public array $normalizedSensors = [];

    public function connect(Telematic $telematic): void
    {
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true, 'message' => 'Connected.', 'metadata' => []];
    }

    public function fetchDevices(array $options = []): array
    {
        return ['devices' => [], 'next_cursor' => null, 'has_more' => false];
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
        $this->normalizedSensors[] = $payload;

        if (($payload['name'] ?? null) === 'broken') {
            throw new RuntimeException('Unable to normalize sensor.');
        }

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

class FleetOpsTelematicServiceTelemetryServiceFake extends TelematicService
{
    public array $storedSensors = [];

    public function __construct()
    {
        parent::__construct(new TelematicProviderRegistry());
    }

    public function storeSensor(Telematic $telematic, array $sensorData, ?Device $device = null): Sensor
    {
        $this->storedSensors[] = compact('telematic', 'sensorData', 'device');

        return new Sensor();
    }
}

function fleetOpsTelematicServiceTelemetryInvoke(TelematicService $service, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($service, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($service, $arguments);
}

test('telematic service updates attached vehicle telemetry from device events', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));
    $GLOBALS['fleetops_telematic_service_broadcasts'] = [];

    $service = new FleetOpsTelematicServiceTelemetryServiceFake();
    $vehicle = new FleetOpsTelematicServiceTelemetryVehicleFake([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'plate_number' => 'FB-100',
        'display_name' => 'Fleet Truck',
        'telematics'   => ['existing' => 'kept'],
    ]);
    $device = new FleetOpsTelematicServiceTelemetryDeviceFake([
        'uuid'      => 'device-uuid',
        'device_id' => 'device-1',
        'status'    => 'active',
        'meta'      => ['device_meta' => 'kept'],
    ]);
    $device->setRelation('attachable', $vehicle);

    $event = new FleetOpsTelematicServiceTelemetryEventFake([
        'uuid'        => 'event-uuid',
        'public_id'   => 'event_public',
        'device_uuid' => 'device-uuid',
        'event_type'  => 'ignition_on',
        'provider'    => 'flespi',
        'occurred_at' => Carbon::parse('2026-07-23 11:59:00'),
    ]);
    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'         => 'telematic-uuid',
        'public_id'    => 'telematic_public',
        'company_uuid' => 'company-uuid',
        'provider'     => 'flespi',
    ], true);

    fleetOpsTelematicServiceTelemetryInvoke($service, 'applyDeviceEventTelemetry', [$event, [
        'device_id'    => 'device-1',
        'location'     => ['latitude' => 1.25, 'longitude' => 103.75],
        'online'       => false,
        'speed'        => 38,
        'heading'      => 90,
        'altitude'     => 15,
        'odometer'     => 12345,
        'ignition'     => true,
        'fuel_level'   => 66,
        'occurred_at'  => '2026-07-23 11:59:00',
    ], $device, true, $telematic]);

    expect($device->saveCount)->toBe(1)
        ->and($device->loadMissingCalls)->toBe(['attachable'])
        ->and($event->positions)->toBe([[
            'latitude'  => 1.25,
            'longitude' => 103.75,
            'heading'   => 90,
            'bearing'   => 90,
            'speed'     => 38,
            'altitude'  => 15,
        ]])
        ->and($vehicle->saveCount)->toBe(1)
        ->and($vehicle->location)->toBeInstanceOf(SpatialPoint::class)
        ->and($vehicle->online)->toBeFalse()
        ->and($vehicle->speed)->toBe(38)
        ->and($vehicle->heading)->toBe(90)
        ->and($vehicle->altitude)->toBe(15)
        ->and($vehicle->odometer)->toBe(12345)
        ->and($vehicle->telematics)->toMatchArray([
            'existing'            => 'kept',
            'last_event_uuid'     => 'event-uuid',
            'last_event_id'       => 'event_public',
            'last_event_type'     => 'ignition_on',
            'last_event_at'       => '2026-07-23 11:59:00',
            'last_device_uuid'    => 'device-uuid',
            'last_provider'       => 'flespi',
            'last_telemetry_data' => [
                'speed'      => 38,
                'heading'    => 90,
                'altitude'   => 15,
                'odometer'   => 12345,
                'ignition'   => true,
                'fuel_level' => 66,
            ],
        ])
        ->and($GLOBALS['fleetops_telematic_service_broadcasts'])->toHaveCount(1)
        ->and($GLOBALS['fleetops_telematic_service_broadcasts'][0]->additionalData)->toBe([
            'source'            => 'telematics',
            'device_event_uuid' => 'event-uuid',
            'provider'          => 'flespi',
        ]);

    Carbon::setTestNow();
});

test('telematic service skips event positions and vehicle updates when event location is missing', function () {
    $GLOBALS['fleetops_telematic_service_broadcasts'] = [];
    $service                                          = new FleetOpsTelematicServiceTelemetryServiceFake();
    $vehicle                                          = new FleetOpsTelematicServiceTelemetryVehicleFake();
    $device                                           = new FleetOpsTelematicServiceTelemetryDeviceFake(['device_id' => 'device-1']);
    $device->setRelation('attachable', $vehicle);
    $event = new FleetOpsTelematicServiceTelemetryEventFake();

    fleetOpsTelematicServiceTelemetryInvoke($service, 'applyDeviceEventTelemetry', [$event, [
        'device_id' => 'device-1',
        'speed'     => 38,
    ], $device, true, null]);

    expect($device->saveCount)->toBe(1)
        ->and($event->positions)->toBe([])
        ->and($vehicle->saveCount)->toBe(0)
        ->and($GLOBALS['fleetops_telematic_service_broadcasts'])->toBe([]);
});

test('telematic service stores list snapshot sensors and skips provider failures', function () {
    $service   = new FleetOpsTelematicServiceTelemetryServiceFake();
    $provider  = new FleetOpsTelematicServiceTelemetryProviderFake();
    $telematic = new Telematic();
    $device    = new FleetOpsTelematicServiceTelemetryDeviceFake(['device_id' => 'device-1']);

    $stored = fleetOpsTelematicServiceTelemetryInvoke($service, 'storeSnapshotSensors', [$telematic, $provider, [
        'device_id' => 'device-1',
        'sensors'   => [
            ['name' => 'temperature', 'type' => 'temperature', 'value' => 22],
            'not-a-sensor',
            ['name' => 'broken', 'type' => 'fault'],
            ['type' => 'fuel', 'value' => 66],
        ],
    ], $device]);

    expect($stored)->toBe(2)
        ->and($provider->normalizedSensors)->toHaveCount(3)
        ->and($service->storedSensors)->toHaveCount(2)
        ->and($service->storedSensors[0]['sensorData'])->toMatchArray([
            'device_id' => 'device-1',
            'name'      => 'temperature',
            'type'      => 'temperature',
            'value'     => 22,
        ])
        ->and($service->storedSensors[1]['sensorData'])->toMatchArray([
            'device_id' => 'device-1',
            'type'      => 'fuel',
            'value'     => 66,
        ])
        ->and(fleetOpsTelematicServiceTelemetryInvoke($service, 'storeSnapshotSensors', [$telematic, $provider, [
            'device_id' => 'device-1',
            'sensors'   => [],
        ], $device]))->toBe(0)
        ->and(fleetOpsTelematicServiceTelemetryInvoke($service, 'storeSnapshotSensors', [$telematic, $provider, [
            'device_id' => 'device-1',
            'sensors'   => 'invalid',
        ], $device]))->toBe(0)
        ->and(fleetOpsTelematicServiceTelemetryInvoke($service, 'normalizeRawSensorList', [[
            ['name' => 'battery', 'value' => 95],
            'invalid',
        ]]))->toBe([
            0 => ['name' => 'battery', 'value' => 95],
        ]);
});
