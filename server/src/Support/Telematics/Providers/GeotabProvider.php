<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Carbon;
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

        $response = $this->apiCall('Get', [
            'typeName'     => 'Device',
            'resultsLimit' => $limit,
        ]);

        $devices      = $response['result'] ?? [];
        $logsByDevice = $this->fetchLatestLogRecords($devices, $options);
        $devices      = array_map(function ($device) use ($logsByDevice) {
            $deviceId = $device['id'] ?? null;
            if ($deviceId && isset($logsByDevice[$deviceId])) {
                $device['latest_log_record'] = $logsByDevice[$deviceId];
            }

            return $device;
        }, $devices);

        return [
            'devices'     => $devices,
            'next_cursor' => null,
            'has_more'    => false,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = $this->apiCall('Get', [
            'typeName' => 'Device',
            'search'   => ['id' => $externalId],
        ]);

        return $response['result'][0] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        $latestLog    = $payload['latest_log_record'] ?? [];
        $hasTelemetry = isset($latestLog['dateTime']) || isset($latestLog['latitude'], $latestLog['longitude']);

        return [
            'device_id'     => $payload['id'] ?? null,
            'external_id'   => $payload['id'] ?? null,
            'name'          => $payload['name'] ?? 'Unknown Device',
            'provider'      => 'geotab',
            'model'         => $payload['deviceType'] ?? null,
            'imei'          => $payload['serialNumber'] ?? null,
            'vin'           => $payload['vehicleIdentificationNumber'] ?? null,
            'serial_number' => $payload['serialNumber'] ?? null,
            'status'        => 'active',
            'online'        => $hasTelemetry ? true : null,
            'last_seen_at'  => $latestLog ? $this->parseTimestamp($latestLog['dateTime'] ?? null) : null,
            'location'      => [
                'lat' => $latestLog['latitude'] ?? null,
                'lng' => $latestLog['longitude'] ?? null,
            ],
            'speed'    => $latestLog['speed'] ?? null,
            'heading'  => $latestLog['bearing'] ?? $latestLog['heading'] ?? null,
            'altitude' => $latestLog['altitude'] ?? null,
            'meta'     => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'active_from' => $payload['activeFrom'] ?? null,
                    'active_to'   => $payload['activeTo'] ?? null,
                    'groups'      => $payload['groups'] ?? null,
                    'has_log'     => $hasTelemetry,
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $latestLog    = $payload['latest_log_record'] ?? $payload;
        $deviceId     = data_get($latestLog, 'device.id') ?? $payload['deviceId'] ?? $payload['id'] ?? null;
        $hasTelemetry = isset($latestLog['dateTime']) || isset($latestLog['latitude'], $latestLog['longitude']);

        return [
            'external_id' => $latestLog['id'] ?? $payload['id'] ?? null,
            'device_id'   => $deviceId,
            'event_type'  => $payload['type'] ?? 'status_data',
            'occurred_at' => $this->parseTimestamp($latestLog['dateTime'] ?? null) ?? now(),
            'online'      => $hasTelemetry ? true : null,
            'location'    => [
                'lat' => $latestLog['latitude'] ?? null,
                'lng' => $latestLog['longitude'] ?? null,
            ],
            'speed'    => $latestLog['speed'] ?? null,
            'heading'  => $latestLog['bearing'] ?? $latestLog['heading'] ?? null,
            'altitude' => $latestLog['altitude'] ?? null,
            'meta'     => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'has_log' => $hasTelemetry,
                    'device'  => data_get($latestLog, 'device.id'),
                ], fn ($value) => $value !== null),
            ],
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

    protected function apiCall(string $method, array $params): array
    {
        $params['credentials'] = [
            'database'  => $this->credentials['database'],
            'sessionId' => $this->sessionId,
        ];

        return Http::post($this->baseUrl, [
            'method' => $method,
            'params' => $params,
        ])->json() ?? [];
    }

    protected function fetchLatestLogRecords(array $devices, array $options = []): array
    {
        $deviceIds = array_values(array_filter(array_map(fn ($device) => $device['id'] ?? null, $devices)));
        if (empty($deviceIds)) {
            return [];
        }

        $fromDate = $options['from_date'] ?? Carbon::now()->subHours(24)->toIso8601String();
        $response = $this->apiCall('Get', [
            'typeName'     => 'LogRecord',
            'search'       => ['fromDate' => $fromDate],
            'resultsLimit' => max(count($deviceIds) * 5, 100),
        ]);

        $logsByDevice = [];
        foreach ($response['result'] ?? [] as $record) {
            $deviceId = data_get($record, 'device.id');
            if (!$deviceId || !in_array($deviceId, $deviceIds, true)) {
                continue;
            }

            $current = $logsByDevice[$deviceId] ?? null;
            if (!$current || Carbon::parse($record['dateTime'] ?? now())->greaterThan(Carbon::parse($current['dateTime'] ?? now()))) {
                $logsByDevice[$deviceId] = $record;
            }
        }

        return $logsByDevice;
    }

    protected function parseTimestamp($value): ?string
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value)->toDateTimeString();
    }
}
