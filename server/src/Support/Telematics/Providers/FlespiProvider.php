<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Carbon;

/**
 * Class FlespiProvider.
 *
 * Flespi telematics provider implementation.
 * https://flespi.com/
 */
class FlespiProvider extends AbstractProvider
{
    protected string $baseUrl        = 'https://flespi.io/gw';
    protected int $requestsPerMinute = 100;

    protected function prepareAuthentication(): void
    {
        $this->headers = [
            'Authorization' => 'FlespiToken ' . $this->credentials['token'],
            'Accept'        => 'application/json',
        ];
    }

    public function testConnection(array $credentials): array
    {
        try {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            // Test by fetching channels
            $response = $this->request('GET', '/channels/all');

            return [
                'success'  => true,
                'message'  => 'Connection successful',
                'metadata' => [
                    'channels_count' => count($response['result'] ?? []),
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'metadata' => [],
            ];
        }
    }

    public function fetchDevices(array $options = []): array
    {
        $limit  = $options['limit'] ?? 100;
        $cursor = $options['cursor'] ?? null;

        $params = ['count' => $limit];
        if ($cursor) {
            $params['offset'] = $cursor;
        }

        $response = $this->request('GET', '/devices/all', $params);

        $devices    = $response['result'] ?? [];
        $nextCursor = count($devices) >= $limit ? ((int) ($cursor ?? 0) + $limit) : null;

        return [
            'devices'     => $devices,
            'next_cursor' => $nextCursor,
            'has_more'    => $nextCursor !== null,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = $this->request('GET', "/devices/{$externalId}");

        return $response['result'][0] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        $telemetry  = $payload['telemetry'] ?? $payload;
        $externalId = $payload['id'] ?? $payload['device.id'] ?? null;
        $occurredAt = $this->parseTimestamp($telemetry['timestamp'] ?? $payload['last_active'] ?? null);

        return [
            'device_id'     => $externalId,
            'external_id'   => $externalId,
            'name'          => $payload['name'] ?? $telemetry['device.name'] ?? 'Unknown Device',
            'provider'      => 'flespi',
            'model'         => $payload['device_type_id'] ?? $payload['device_type_name'] ?? null,
            'imei'          => $payload['configuration']['ident'] ?? $payload['ident'] ?? $telemetry['ident'] ?? null,
            'serial_number' => $payload['configuration']['serial'] ?? $payload['serial'] ?? null,
            'phone'         => $payload['configuration']['phone'] ?? null,
            'status'        => isset($payload['telemetry']) ? 'active' : 'inactive',
            'online'        => $this->resolveOnline($telemetry),
            'last_seen_at'  => $occurredAt,
            'location'      => [
                'lat' => $telemetry['position.latitude'] ?? null,
                'lng' => $telemetry['position.longitude'] ?? null,
            ],
            'speed'      => $telemetry['position.speed'] ?? $telemetry['vehicle.speed'] ?? null,
            'heading'    => $telemetry['position.direction'] ?? $telemetry['position.heading'] ?? null,
            'altitude'   => $telemetry['position.altitude'] ?? null,
            'odometer'   => $telemetry['vehicle.mileage'] ?? $telemetry['vehicle.odometer'] ?? null,
            'ignition'   => $this->extractIgnition($telemetry),
            'fuel_level' => $telemetry['fuel.level'] ?? $telemetry['can.fuel.level'] ?? null,
            'meta'       => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'status'    => $payload['status'] ?? $telemetry['status'] ?? null,
                    'online'    => $telemetry['online'] ?? null,
                    'connected' => $telemetry['device.connected'] ?? $telemetry['connected'] ?? null,
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $deviceId = $payload['device.id'] ?? $payload['id'] ?? null;

        return [
            'external_id' => $payload['id'] ?? $deviceId,
            'device_id'   => $deviceId,
            'event_type'  => $payload['event.enum'] ?? 'telemetry_update',
            'occurred_at' => $this->parseTimestamp($payload['timestamp'] ?? null) ?? now(),
            'online'      => $this->resolveOnline($payload),
            'location'    => [
                'lat' => $payload['position.latitude'] ?? null,
                'lng' => $payload['position.longitude'] ?? null,
            ],
            'speed'      => $payload['position.speed'] ?? $payload['vehicle.speed'] ?? null,
            'heading'    => $payload['position.direction'] ?? $payload['position.heading'] ?? null,
            'altitude'   => $payload['position.altitude'] ?? null,
            'odometer'   => $payload['vehicle.mileage'] ?? $payload['vehicle.odometer'] ?? null,
            'ignition'   => $this->extractIgnition($payload),
            'fuel_level' => $payload['fuel.level'] ?? $payload['can.fuel.level'] ?? null,
            'meta'       => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'status'    => $payload['status'] ?? null,
                    'online'    => $payload['online'] ?? null,
                    'connected' => $payload['device.connected'] ?? $payload['connected'] ?? null,
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        return [
            'sensor_type' => $payload['sensor_type'] ?? 'generic',
            'value'       => $payload['value'] ?? null,
            'unit'        => $payload['unit'] ?? null,
            'recorded_at' => isset($payload['timestamp']) ? date('Y-m-d H:i:s', $payload['timestamp']) : now(),
            'meta'        => $payload,
        ];
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        if (!isset($credentials['webhook_secret'])) {
            return true; // No secret configured, skip validation
        }

        $expectedSignature = hash_hmac('sha256', $payload, $credentials['webhook_secret']);

        return hash_equals($expectedSignature, $signature);
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        $devices = [];
        $events  = [];

        // Flespi sends array of messages
        foreach ($payload as $message) {
            if (isset($message['device.id'])) {
                $devices[] = $this->normalizeDevice([
                    'id'        => $message['device.id'],
                    'name'      => $message['device.name'] ?? null,
                    'telemetry' => $message,
                ]);

                $events[] = $this->normalizeEvent($message);
            }
        }

        return [
            'devices' => $devices,
            'events'  => $events,
            'sensors' => [],
        ];
    }

    public function getCredentialSchema(): array
    {
        return [
            [
                'name'        => 'token',
                'label'       => 'Flespi Token',
                'type'        => 'password',
                'placeholder' => 'Enter your Flespi API token',
                'required'    => true,
                'validation'  => 'required|string|min:20',
            ],
            [
                'name'        => 'webhook_secret',
                'label'       => 'Webhook Secret (Optional)',
                'type'        => 'password',
                'placeholder' => 'Enter webhook secret for signature validation',
                'required'    => false,
            ],
        ];
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    protected function parseTimestamp($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((float) $value)->toDateTimeString();
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    protected function resolveOnline(array $payload): ?bool
    {
        $value = $payload['online'] ?? $payload['device.connected'] ?? $payload['connected'] ?? null;

        if ($value === null) {
            return isset($payload['timestamp']);
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    protected function extractIgnition(array $payload): ?bool
    {
        $value = $payload['engine.ignition.status'] ?? $payload['ignition.status'] ?? null;

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
