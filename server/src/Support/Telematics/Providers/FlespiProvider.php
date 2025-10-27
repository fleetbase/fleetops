<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

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
        return [
            'external_id'     => $payload['id'],
            'device_name'     => $payload['name'] ?? 'Unknown Device',
            'device_provider' => 'flespi',
            'device_model'    => $payload['device_type_id'] ?? null,
            'imei'            => $payload['configuration']['ident'] ?? null,
            'phone'           => $payload['configuration']['phone'] ?? null,
            'status'          => isset($payload['telemetry']) ? 'active' : 'inactive',
            'location'        => [
                'lat' => $payload['telemetry']['position.latitude'] ?? null,
                'lng' => $payload['telemetry']['position.longitude'] ?? null,
            ],
            'meta' => $payload,
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        return [
            'external_id' => $payload['id'] ?? null,
            'event_type'  => $payload['event.enum'] ?? 'telemetry_update',
            'occurred_at' => isset($payload['timestamp']) ? date('Y-m-d H:i:s', $payload['timestamp']) : now(),
            'location'    => [
                'lat' => $payload['position.latitude'] ?? null,
                'lng' => $payload['position.longitude'] ?? null,
            ],
            'meta' => $payload,
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
}
