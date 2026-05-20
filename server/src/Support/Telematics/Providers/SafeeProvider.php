<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Safee Tracking REST provider implementation.
 *
 * Safee authenticates through an OpenID Connect token endpoint and exposes
 * vehicle discovery plus latest state/position endpoints under api/v2.
 */
class SafeeProvider extends AbstractProvider
{
    protected string $baseUrl        = '';
    protected int $requestsPerMinute = 3000;
    protected ?string $accessToken   = null;

    protected function prepareAuthentication(): void
    {
        $this->baseUrl     = rtrim($this->credentials['api_base_url'] ?? $this->credentials['server_uri'] ?? '', '/');
        $this->accessToken = $this->credentials['access_token'] ?? $this->authenticate();
        $scheme            = $this->credentials['authorization_scheme'] ?? 'Bearer';

        $this->headers = [
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'Accept-Language' => $this->credentials['language'] ?? 'en',
            'Authorization'   => trim($scheme . ' ' . $this->accessToken),
        ];
    }

    public function testConnection(array $credentials): array
    {
        try {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            $response = $this->safeeGet('/api/v2/status');

            return [
                'success'  => (int) ($response['code'] ?? 0) === 0,
                'message'  => $response['message'] ?? 'Connection successful',
                'metadata' => [
                    'status' => $response['status'] ?? null,
                    'time'   => $response['time'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'metadata' => [],
            ];
        }
    }

    public function fetchDevices(array $options = []): array
    {
        if (!empty($options['vehicles'])) {
            $response = $this->safeePost('/api/v2/vehicle/last-state', [
                'live'      => $options['live'] ?? true,
                'startDate' => $options['start_date'] ?? null,
                'endDate'   => $options['end_date'] ?? null,
                'vehicles'  => $options['vehicles'],
            ]);

            $devices = $response['result'] ?? [];
        } else {
            $response = $this->safeePost('/api/v2/vehicle/list-info', $options['filter'] ?? new \stdClass());
            $devices  = $response['result'] ?? [];
        }

        return [
            'devices'     => $devices,
            'next_cursor' => null,
            'has_more'    => false,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = $this->safeePost('/api/v2/vehicle/info', [
            'id' => is_numeric($externalId) ? (int) $externalId : $externalId,
        ]);

        return $response['result'] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        $position = $this->extractPosition($payload);

        return [
            'external_id'     => $payload['id'] ?? data_get($payload, 'vehicle.id') ?? null,
            'device_name'     => $payload['name'] ?? $payload['plateNumber'] ?? data_get($payload, 'vehicle.name') ?? 'Unknown Vehicle',
            'device_provider' => 'safee',
            'device_model'    => $payload['model'] ?? data_get($payload, 'device.model') ?? null,
            'imei'            => data_get($payload, 'device.imei') ?? data_get($payload, 'device.serial') ?? null,
            'vin'             => $payload['vin'] ?? null,
            'status'          => $this->normalizeVehicleStatus($payload['status'] ?? $payload['vehicleStatus'] ?? null),
            'location'        => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'meta' => [
                'raw'          => $payload,
                'plate_number' => $payload['plateNumber'] ?? $payload['plate_number'] ?? null,
                'door_number'  => $payload['doorNumber'] ?? null,
                'driver'       => $payload['driver'] ?? null,
                'last_update'  => $this->normalizeEvent($payload),
                'capabilities' => [
                    'tracking'       => isset($position['lat'], $position['lng']),
                    'odometer'       => $this->extractOdometer($payload) !== null,
                    'fuel_level'     => $this->extractFuelLevel($payload) !== null,
                    'ignition_state' => $this->extractIgnition($payload) !== null,
                ],
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $position = $this->extractPosition($payload);

        return [
            'external_id' => $payload['id'] ?? data_get($payload, 'vehicle.id') ?? null,
            'event_type'  => data_get($payload, 'event.code') ?? data_get($payload, 'event.name') ?? 'telemetry_update',
            'occurred_at' => $this->parseTimestamp($payload['date'] ?? $payload['deviceTime'] ?? $payload['time'] ?? null),
            'location'    => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'speed'      => $payload['speed'] ?? $payload['lastSpeed'] ?? null,
            'heading'    => $payload['heading'] ?? $payload['angle'] ?? null,
            'odometer'   => $this->extractOdometer($payload),
            'ignition'   => $this->extractIgnition($payload),
            'fuel_level' => $this->extractFuelLevel($payload),
            'meta'       => $payload,
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        return [
            'sensor_type' => $payload['type'] ?? $payload['name'] ?? 'generic',
            'value'       => $payload['value'] ?? $payload['lastValue'] ?? null,
            'unit'        => $payload['unit'] ?? null,
            'recorded_at' => $this->parseTimestamp($payload['date'] ?? $payload['deviceTime'] ?? null),
            'meta'        => $payload,
        ];
    }

    public function getCredentialSchema(): array
    {
        return [
            [
                'name'        => 'server_uri',
                'label'       => 'Server URI',
                'type'        => 'text',
                'placeholder' => 'https://tracking.example.com',
                'required'    => true,
                'validation'  => 'required|url',
            ],
            [
                'name'        => 'realm_id',
                'label'       => 'Realm ID',
                'type'        => 'text',
                'placeholder' => 'Enter Safee realm ID',
                'required'    => true,
            ],
            [
                'name'        => 'client_id',
                'label'       => 'Client ID',
                'type'        => 'text',
                'placeholder' => 'Enter Safee client ID',
                'required'    => true,
            ],
            [
                'name'        => 'client_secret',
                'label'       => 'Client Secret',
                'type'        => 'password',
                'placeholder' => 'Enter Safee client secret',
                'required'    => true,
            ],
            [
                'name'        => 'username',
                'label'       => 'Username',
                'type'        => 'text',
                'placeholder' => 'Enter Safee username',
                'required'    => true,
            ],
            [
                'name'        => 'password',
                'label'       => 'Password',
                'type'        => 'password',
                'placeholder' => 'Enter Safee password',
                'required'    => true,
            ],
        ];
    }

    protected function authenticate(): string
    {
        foreach (['server_uri', 'realm_id', 'client_id', 'client_secret', 'username', 'password'] as $field) {
            if (empty($this->credentials[$field])) {
                throw new \InvalidArgumentException("Safee credential '{$field}' is required.");
            }
        }

        $tokenUrl = rtrim($this->credentials['server_uri'], '/') . '/auth/realms/' . $this->credentials['realm_id'] . '/protocol/openid-connect/token';

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($tokenUrl, [
                'grant_type'    => 'password',
                'client_secret' => $this->credentials['client_secret'],
                'client_id'     => $this->credentials['client_id'],
                'username'      => $this->credentials['username'],
                'password'      => $this->credentials['password'],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Safee authentication failed: ' . $response->body());
        }

        $token = $response->json('access_token');

        if (!$token) {
            throw new \RuntimeException('Safee authentication did not return an access token.');
        }

        return $token;
    }

    protected function safeeGet(string $endpoint): array
    {
        $response = Http::withHeaders($this->headers)
            ->timeout(30)
            ->get($this->baseUrl . $endpoint);

        if ($response->failed()) {
            throw new \RuntimeException('Safee API request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    protected function safeePost(string $endpoint, array|\stdClass $payload = []): array
    {
        $response = Http::withHeaders($this->headers)
            ->timeout(30)
            ->post($this->baseUrl . $endpoint, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Safee API request failed: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    protected function extractPosition(array $payload): array
    {
        $position = $payload['position'] ?? $payload['lastPosition'] ?? $payload['location'] ?? $payload;

        return [
            'lat' => $position['lat'] ?? $position['latitude'] ?? data_get($position, 'loc.coordinates.1'),
            'lng' => $position['lon'] ?? $position['lng'] ?? $position['longitude'] ?? data_get($position, 'loc.coordinates.0'),
        ];
    }

    protected function parseTimestamp($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (float) $value;
            $seconds   = $timestamp > 9999999999 ? $timestamp / 1000 : $timestamp;

            return Carbon::createFromTimestamp($seconds)->toDateTimeString();
        }

        return Carbon::parse($value)->toDateTimeString();
    }

    protected function normalizeVehicleStatus(?string $status): string
    {
        if (!$status) {
            return 'active';
        }

        return in_array(strtolower($status), ['offline', 'inactive', 'deleted', 'expired'], true) ? 'inactive' : 'active';
    }

    protected function extractOdometer(array $payload): mixed
    {
        return data_get($payload, 'canbus.odometer')
            ?? data_get($payload, 'vehicleCanbus.odometer')
            ?? data_get($payload, 'odometer')
            ?? null;
    }

    protected function extractIgnition(array $payload): ?bool
    {
        $value = $payload['ignition'] ?? data_get($payload, 'event.code');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && str_contains(strtolower($value), 'ignition_on')) {
            return true;
        }

        if (is_string($value) && str_contains(strtolower($value), 'ignition_off')) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    protected function extractFuelLevel(array $payload): mixed
    {
        return data_get($payload, 'fuel.level')
            ?? data_get($payload, 'fuelLevel')
            ?? data_get($payload, 'vehicleFuel.level')
            ?? null;
    }
}
