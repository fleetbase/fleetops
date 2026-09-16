<?php

use Fleetbase\FleetOps\Contracts\TelemetryProviderInterface;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AbstractProvider;
use Fleetbase\FleetOps\Support\Telematics\Telemetry\Sample;

/** Deliberately uses a protocol unrelated to any bundled vendor. */
class ExampleTelemetryProvider extends AbstractProvider implements TelemetryProviderInterface
{
    public static int $connections = 0;
    public static array $pages     = [];

    protected function prepareAuthentication(): void
    {
    }

    public function connect(Telematic $telematic): void
    {
        self::$connections++;
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true];
    }

    public function fetchDevices(array $options = []): array
    {
        return array_shift(self::$pages);
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return [];
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function telemetryOptions(): array
    {
        return ['polling_enabled' => true, 'webhooks_enabled' => true, 'page_size' => 2, 'stale_engine_on_seconds' => 90];
    }

    public function telemetryUnits(array $payload): array
    {
        $samples = $payload['signals'] ?? (isset($payload['tracker']) ? [$payload] : $payload);
        foreach ($samples as $sample) {
            if (!is_array($sample) || !isset($sample['tracker'], $sample['measured'], $sample['point'])) {
                throw new InvalidArgumentException('Unsupported signal');
            }
        }

        return $samples;
    }

    public function normalizeDevice(array $payload): array
    {
        return ['device_id' => $payload['tracker'], 'last_seen_at' => Sample::timestamp($payload['received']), 'meta' => []];
    }

    public function normalizeEvent(array $payload): array
    {
        return ['device_id' => $payload['tracker'], 'event_type' => 'telemetry_update',
            'occurred_at'   => Sample::timestamp($payload['measured']), 'last_seen_at' => Sample::timestamp($payload['received']),
            'location'      => ['lat' => $payload['point'][1], 'lng' => $payload['point'][0]], 'ignition' => true,
            'meta'          => ['telemetry' => ['provider_at' => Sample::timestamp($payload['received'])]]];
    }

    public function normalizeTelemetrySnapshot(array $payload): array
    {
        return ['device' => $this->normalizeDevice($payload), 'event' => $this->normalizeEvent($payload), 'sensors' => []];
    }
}
