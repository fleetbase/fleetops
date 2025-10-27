<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Class GeotabProvider.
 *
 * Geotab telematics provider implementation.
 * https://www.geotab.com/
 */
class GeotabProvider extends AbstractProvider
{
    protected string $baseUrl        = 'https://my.geotab.com/apiv1';
    protected int $requestsPerMinute = 50;
    protected ?string $sessionId     = null;

    protected function prepareAuthentication(): void
    {
        // Geotab uses session-based authentication
        if (!$this->sessionId) {
            $this->authenticate();
        }

        $this->headers = [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Authenticate with Geotab and get session ID.
     */
    protected function authenticate(): void
    {
        $response = Http::post($this->baseUrl, [
            'method' => 'Authenticate',
            'params' => [
                'database' => $this->credentials['database'],
                'userName' => $this->credentials['username'],
                'password' => $this->credentials['password'],
            ],
        ])->json();

        if (isset($response['result']['credentials']['sessionId'])) {
            $this->sessionId = $response['result']['credentials']['sessionId'];
        } else {
            throw new \Exception('Geotab authentication failed');
        }
    }

    public function testConnection(array $credentials): array
    {
        try {
            $this->credentials = $credentials;
            $this->authenticate();

            return [
                'success'  => true,
                'message'  => 'Connection successful',
                'metadata' => [
                    'session_id' => substr($this->sessionId, 0, 10) . '...',
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
        $limit = $options['limit'] ?? 100;

        $response = Http::post($this->baseUrl, [
            'method' => 'Get',
            'params' => [
                'credentials' => [
                    'database'  => $this->credentials['database'],
                    'sessionId' => $this->sessionId,
                ],
                'typeName'     => 'Device',
                'resultsLimit' => $limit,
            ],
        ])->json();

        $devices = $response['result'] ?? [];

        return [
            'devices'     => $devices,
            'next_cursor' => null,
            'has_more'    => false,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = Http::post($this->baseUrl, [
            'method' => 'Get',
            'params' => [
                'credentials' => [
                    'database'  => $this->credentials['database'],
                    'sessionId' => $this->sessionId,
                ],
                'typeName' => 'Device',
                'search'   => ['id' => $externalId],
            ],
        ])->json();

        return $response['result'][0] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        return [
            'external_id'     => $payload['id'],
            'device_name'     => $payload['name'] ?? 'Unknown Device',
            'device_provider' => 'geotab',
            'device_model'    => $payload['deviceType'] ?? null,
            'imei'            => $payload['serialNumber'] ?? null,
            'vin'             => $payload['vehicleIdentificationNumber'] ?? null,
            'status'          => 'active',
            'meta'            => $payload,
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        return [
            'external_id' => $payload['id'] ?? null,
            'event_type'  => $payload['type'] ?? 'status_data',
            'occurred_at' => $payload['dateTime'] ?? now(),
            'meta'        => $payload,
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        return [
            'sensor_type' => $payload['diagnosticType'] ?? 'generic',
            'value'       => $payload['data'] ?? null,
            'recorded_at' => $payload['dateTime'] ?? now(),
            'meta'        => $payload,
        ];
    }

    public function getCredentialSchema(): array
    {
        return [
            [
                'name'        => 'database',
                'label'       => 'Database Name',
                'type'        => 'text',
                'placeholder' => 'Enter your Geotab database name',
                'required'    => true,
            ],
            [
                'name'        => 'username',
                'label'       => 'Username',
                'type'        => 'text',
                'placeholder' => 'Enter your Geotab username',
                'required'    => true,
            ],
            [
                'name'        => 'password',
                'label'       => 'Password',
                'type'        => 'password',
                'placeholder' => 'Enter your Geotab password',
                'required'    => true,
            ],
        ];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }
}
