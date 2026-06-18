<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Carbon;

/**
 * Class SamsaraProvider.
 *
 * Samsara telematics provider implementation.
 * https://www.samsara.com/
 */
class SamsaraProvider extends AbstractProvider
{
    protected string $baseUrl        = 'https://api.samsara.com';
    protected int $requestsPerMinute = 60;

    protected function prepareAuthentication(): void
    {
        $this->headers = [
            'Authorization' => 'Bearer ' . $this->credentials['api_token'],
            'Accept'        => 'application/json',
        ];
    }

    public function testConnection(array $credentials): array
    {
        try {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            // Test by fetching user info
            $response = $this->request('GET', '/fleet/users');

            return [
                'success'  => true,
                'message'  => 'Connection successful',
                'metadata' => [
                    'users_count' => count($response['data'] ?? []),
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

        $params = ['limit' => $limit];
        if ($cursor) {
            $params['after'] = $cursor;
        }

        $response = $this->request('GET', '/fleet/vehicles', $params);

        $devices    = $response['data'] ?? [];
        $nextCursor = $response['pagination']['endCursor'] ?? null;
        $hasMore    = $response['pagination']['hasNextPage'] ?? false;

        return [
            'devices'     => $devices,
            'next_cursor' => $hasMore ? $nextCursor : null,
            'has_more'    => $hasMore,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = $this->request('GET', "/fleet/vehicles/{$externalId}");

        return $response['data'] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        $position = $this->extractPosition($payload);

        return [
            'device_id'      => $payload['id'] ?? null,
            'external_id'    => $payload['id'] ?? null,
            'name'           => $payload['name'] ?? 'Unknown Device',
            'provider'       => 'samsara',
            'model'          => $payload['make'] ?? $payload['model'] ?? null,
            'vin'            => $payload['vin'] ?? null,
            'serial_number'  => $payload['serial'] ?? $payload['serialNumber'] ?? null,
            'license_plate'  => $payload['licensePlate'] ?? null,
            'status'         => $this->normalizeStatus($payload),
            'online'         => $this->resolveOnline($payload),
            'last_seen_at'   => $this->parseTimestamp($payload['time'] ?? data_get($payload, 'location.time') ?? data_get($payload, 'gps.time') ?? null),
            'location'       => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'speed'      => $this->extractSpeed($payload),
            'heading'    => $this->extractHeading($payload),
            'altitude'   => $this->extractAltitude($payload),
            'odometer'   => data_get($payload, 'odometerMeters') ?? data_get($payload, 'obdOdometerMeters.value'),
            'fuel_level' => data_get($payload, 'fuelPercent.value') ?? data_get($payload, 'fuelPercent'),
            'meta'       => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'status'         => $payload['status'] ?? null,
                    'gateway_status' => data_get($payload, 'gateway.status'),
                    'online'         => $payload['online'] ?? $payload['isOnline'] ?? data_get($payload, 'gateway.online'),
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $position = $this->extractPosition($payload);
        $deviceId = data_get($payload, 'vehicle.id') ?? $payload['vehicleId'] ?? $payload['id'] ?? null;

        return [
            'external_id' => $payload['id'] ?? $deviceId,
            'device_id'   => $deviceId,
            'event_type'  => $payload['eventType'] ?? 'vehicle_update',
            'occurred_at' => $this->parseTimestamp($payload['time'] ?? data_get($payload, 'location.time') ?? data_get($payload, 'gps.time') ?? null) ?? now(),
            'online'      => $this->resolveOnline($payload),
            'location'    => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'speed'      => $this->extractSpeed($payload),
            'heading'    => $this->extractHeading($payload),
            'altitude'   => $this->extractAltitude($payload),
            'odometer'   => data_get($payload, 'odometerMeters') ?? data_get($payload, 'obdOdometerMeters.value'),
            'fuel_level' => data_get($payload, 'fuelPercent.value') ?? data_get($payload, 'fuelPercent'),
            'meta'       => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'status'         => $payload['status'] ?? null,
                    'gateway_status' => data_get($payload, 'gateway.status'),
                    'online'         => $payload['online'] ?? $payload['isOnline'] ?? data_get($payload, 'gateway.online'),
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        return [
            'sensor_type' => $payload['sensorType'] ?? 'generic',
            'value'       => $payload['value'] ?? null,
            'unit'        => $payload['unit'] ?? null,
            'recorded_at' => $payload['time'] ?? now(),
            'meta'        => $payload,
        ];
    }

    public function validateWebhookSignature(string $payload, string $signature, array $credentials): bool
    {
        if (!isset($credentials['webhook_secret'])) {
            return true;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $credentials['webhook_secret']);

        return hash_equals($expectedSignature, $signature);
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        $devices = [];
        $events  = [];

        // Samsara webhook structure
        if (isset($payload['data'])) {
            foreach ($payload['data'] as $item) {
                if (isset($item['vehicle'])) {
                    $devices[]         = $this->normalizeDevice($item['vehicle']);
                    $item['vehicleId'] = $item['vehicle']['id'] ?? null;
                }

                $events[] = $this->normalizeEvent($item);
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
                'name'        => 'api_token',
                'label'       => 'API Token',
                'type'        => 'password',
                'placeholder' => 'Enter your Samsara API token',
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

    protected function extractPosition(array $payload): array
    {
        $position = $payload['location']
            ?? $payload['gps']
            ?? $payload['currentLocation']
            ?? $payload['lastKnownLocation']
            ?? data_get($payload, 'vehicle.location')
            ?? [];

        return [
            'lat' => $position['latitude'] ?? $position['lat'] ?? null,
            'lng' => $position['longitude'] ?? $position['lng'] ?? null,
        ];
    }

    protected function extractSpeed(array $payload): mixed
    {
        return data_get($payload, 'location.speedMilesPerHour')
            ?? data_get($payload, 'location.speed')
            ?? data_get($payload, 'gps.speedMilesPerHour')
            ?? data_get($payload, 'speedMilesPerHour')
            ?? data_get($payload, 'speed');
    }

    protected function extractHeading(array $payload): mixed
    {
        return data_get($payload, 'location.headingDegrees')
            ?? data_get($payload, 'location.heading')
            ?? data_get($payload, 'gps.headingDegrees')
            ?? data_get($payload, 'headingDegrees')
            ?? data_get($payload, 'heading');
    }

    protected function extractAltitude(array $payload): mixed
    {
        return data_get($payload, 'location.altitudeMeters')
            ?? data_get($payload, 'gps.altitudeMeters')
            ?? data_get($payload, 'altitudeMeters')
            ?? data_get($payload, 'altitude');
    }

    protected function parseTimestamp($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    protected function resolveOnline(array $payload): ?bool
    {
        $value = $payload['online'] ?? $payload['isOnline'] ?? data_get($payload, 'gateway.online') ?? null;

        if ($value === null) {
            return $this->extractPosition($payload)['lat'] !== null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    protected function normalizeStatus(array $payload): string
    {
        $status = strtolower((string) ($payload['status'] ?? data_get($payload, 'gateway.status') ?? 'active'));

        return in_array($status, ['inactive', 'offline', 'deactivated'], true) ? 'inactive' : 'active';
    }
}
