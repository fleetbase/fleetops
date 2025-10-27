<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

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
        return [
            'external_id'     => $payload['id'],
            'device_name'     => $payload['name'] ?? 'Unknown Device',
            'device_provider' => 'samsara',
            'device_model'    => $payload['make'] ?? null,
            'vin'             => $payload['vin'] ?? null,
            'license_plate'   => $payload['licensePlate'] ?? null,
            'status'          => 'active',
            'meta'            => $payload,
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        return [
            'external_id' => $payload['id'] ?? null,
            'event_type'  => $payload['eventType'] ?? 'vehicle_update',
            'occurred_at' => $payload['time'] ?? now(),
            'location'    => [
                'lat' => $payload['location']['latitude'] ?? null,
                'lng' => $payload['location']['longitude'] ?? null,
            ],
            'meta' => $payload,
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
                    $devices[] = $this->normalizeDevice($item['vehicle']);
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
}
