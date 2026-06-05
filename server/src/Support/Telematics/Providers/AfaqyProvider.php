<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * AFAQY telematics provider implementation.
 *
 * AFAQY uses token-based REST endpoints for authentication, unit discovery,
 * latest unit telemetry, historical signals, and track generation.
 */
class AfaqyProvider extends AbstractProvider
{
    protected string $baseUrl        = 'https://api.afaqy.sa';
    protected int $requestsPerMinute = 60;

    protected function prepareAuthentication(): void
    {
        $this->baseUrl = rtrim($this->credentials['base_url'] ?? $this->baseUrl, '/');
        $token         = $this->credentials['token'] ?? $this->authenticate();

        $this->credentials['token'] = $token;
        $this->headers              = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    public function testConnection(array $credentials): array
    {
        try {
            $this->credentials = $credentials;
            $this->prepareAuthentication();

            $response = $this->afaqyPost('/units/lists', [
                'data' => [
                    'projection' => ['_id', 'name', 'imei', 'last_update'],
                    'filters'    => new \stdClass(),
                ],
            ]);

            return [
                'success'  => true,
                'message'  => 'Connection successful',
                'metadata' => [
                    'units_count' => count($response['data'] ?? []),
                    'status_code' => $response['status_code'] ?? null,
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
        $limit  = (int) ($options['limit'] ?? 100);
        $offset = (int) ($options['cursor'] ?? 0);

        $response = $this->afaqyPost('/units/lists', [
            'data' => [
                'address'    => $options['address'] ?? false,
                'filters'    => $options['filters'] ?? new \stdClass(),
                'projection' => $options['projection'] ?? [
                    '_id',
                    'name',
                    'imei',
                    'sim_number',
                    'device_serial',
                    'active',
                    'driver_id',
                    'last_update',
                    'counters',
                    'profile',
                    'sensors',
                    'sensors_last_val',
                ],
            ],
            'limit'  => $limit,
            'offset' => $offset,
        ]);

        $devices    = $response['data'] ?? [];
        $pagination = $response['pagination'] ?? [];
        $total      = (int) ($pagination['filtersCount'] ?? $pagination['allCount'] ?? count($devices));
        $nextCursor = ($offset + $limit) < $total ? $offset + $limit : null;

        return [
            'devices'     => $devices,
            'next_cursor' => $nextCursor,
            'has_more'    => $nextCursor !== null,
        ];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        $response = $this->afaqyPost('/units/view', [
            'data' => ['id' => $externalId],
        ]);

        return $response['data'] ?? [];
    }

    public function normalizeDevice(array $payload): array
    {
        $lastUpdate = $payload['last_update'] ?? [];

        return [
            'external_id'     => $payload['_id'] ?? $payload['id'] ?? null,
            'device_name'     => $payload['name'] ?? data_get($payload, 'profile.plate_number') ?? 'Unknown Unit',
            'device_provider' => 'afaqy',
            'device_model'    => data_get($payload, 'profile.model') ?? $payload['device'] ?? null,
            'imei'            => $payload['imei'] ?? null,
            'phone'           => $payload['sim_number'] ?? null,
            'vin'             => data_get($payload, 'profile.vin'),
            'status'          => ($payload['active'] ?? false) ? 'active' : 'inactive',
            'location'        => [
                'lat' => $lastUpdate['lat'] ?? null,
                'lng' => $lastUpdate['lng'] ?? null,
            ],
            'meta' => [
                'raw'          => $payload,
                'plate_number' => data_get($payload, 'profile.plate_number'),
                'driver_id'    => $payload['driver_id'] ?? null,
                'last_update'  => $this->normalizeEvent($payload),
                'capabilities' => [
                    'tracking'       => isset($lastUpdate['lat'], $lastUpdate['lng']),
                    'odometer'       => data_get($payload, 'counters.odometer') !== null,
                    'fuel_level'     => $this->extractFuelLevel($payload) !== null,
                    'ignition_state' => $this->extractIgnition($payload) !== null,
                ],
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $lastUpdate = $payload['last_update'] ?? $payload;

        return [
            'external_id' => $payload['_id'] ?? $payload['id'] ?? null,
            'device_id'   => $payload['_id'] ?? $payload['id'] ?? null,
            'event_type'  => $payload['event'] ?? $payload['event_type'] ?? 'telemetry_update',
            'occurred_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? $lastUpdate['dts'] ?? null),
            'location'    => [
                'lat' => $lastUpdate['lat'] ?? data_get($lastUpdate, 'loc.coordinates.1'),
                'lng' => $lastUpdate['lng'] ?? data_get($lastUpdate, 'loc.coordinates.0'),
            ],
            'speed'      => $lastUpdate['speed'] ?? $lastUpdate['spd'] ?? null,
            'heading'    => $lastUpdate['angle'] ?? $lastUpdate['ang'] ?? null,
            'odometer'   => data_get($payload, 'counters.odometer'),
            'ignition'   => $this->extractIgnition($payload),
            'fuel_level' => $this->extractFuelLevel($payload),
            'meta'       => $payload,
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        return [
            'sensor_type' => $payload['type'] ?? $payload['name'] ?? $payload['param'] ?? 'generic',
            'value'       => $payload['value'] ?? $payload['last_val']['value'] ?? $payload['last_update'] ?? null,
            'unit'        => $payload['units'] ?? null,
            'recorded_at' => $this->parseTimestamp($payload['updated_at'] ?? $payload['created_at'] ?? null),
            'meta'        => $payload,
        ];
    }

    public function getCredentialSchema(): array
    {
        return [
            [
                'name'        => 'base_url',
                'label'       => 'Base URL',
                'type'        => 'text',
                'placeholder' => 'https://api.afaqy.sa',
                'required'    => false,
                'validation'  => 'nullable|url',
            ],
            [
                'name'        => 'username',
                'label'       => 'Username',
                'type'        => 'text',
                'placeholder' => 'Enter your AFAQY username',
                'required'    => false,
                'validation'  => 'required_without:token|string',
            ],
            [
                'name'        => 'password',
                'label'       => 'Password',
                'type'        => 'password',
                'placeholder' => 'Enter your AFAQY password',
                'required'    => false,
                'validation'  => 'required_without:token|string',
            ],
            [
                'name'        => 'token',
                'label'       => 'Token',
                'type'        => 'password',
                'placeholder' => 'Use an existing AFAQY token',
                'required'    => false,
                'validation'  => 'required_without:username|string',
            ],
        ];
    }

    protected function authenticate(): string
    {
        if (empty($this->credentials['username']) || empty($this->credentials['password'])) {
            throw new \InvalidArgumentException('AFAQY username/password or token is required.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl . '/auth/login', [
                'data' => [
                    'username' => $this->credentials['username'],
                    'password' => $this->credentials['password'],
                    'expire'   => $this->credentials['expire'] ?? 1,
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('AFAQY authentication failed: ' . $response->body());
        }

        $token = data_get($response->json(), 'data.token');

        if (!$token) {
            throw new \RuntimeException('AFAQY authentication did not return a token.');
        }

        return $token;
    }

    protected function afaqyPost(string $endpoint, array $payload = []): array
    {
        $response = Http::withHeaders($this->headers)
            ->timeout(30)
            ->post($this->baseUrl . $endpoint . '?token=' . urlencode($this->credentials['token']), $payload);

        if ($response->failed()) {
            throw new \RuntimeException('AFAQY API request failed: ' . $response->body());
        }

        return $response->json() ?? [];
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

    protected function extractIgnition(array $payload): ?bool
    {
        $params = data_get($payload, 'last_update.params', []);
        $value  = data_get($params, 'acc') ?? data_get($params, 'di1') ?? data_get($payload, 'counters.last_acc');

        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int) $value === 1);
    }

    protected function extractFuelLevel(array $payload): mixed
    {
        return data_get($payload, 'last_update.params.fuel')
            ?? data_get($payload, 'last_update.params.fuel_level')
            ?? data_get($payload, 'fc.level')
            ?? null;
    }
}
