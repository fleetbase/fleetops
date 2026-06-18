<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AFAQY telematics provider implementation.
 *
 * AFAQY uses token-based REST endpoints for authentication, unit discovery,
 * latest unit telemetry, historical signals, and track generation.
 */
class AfaqyProvider extends AbstractProvider
{
    protected string $baseUrl                   = 'https://api.afaqy.sa';
    protected int $requestsPerMinute            = 60;
    protected int $dataTimeout                  = 120;
    protected int $connectTimeout               = 15;
    protected int $connectionTestTimeout        = 30;
    protected int $connectionTestConnectTimeout = 10;

    protected function prepareAuthentication(): void
    {
        $this->baseUrl = rtrim($this->credentials['base_url'] ?? $this->baseUrl, '/');
        $token         = $this->canRefreshToken() ? $this->authenticate() : ($this->credentials['token'] ?? null);

        if (!$token) {
            throw new \InvalidArgumentException('AFAQY username/password or token is required.');
        }

        $this->setToken($token);
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
            ], true, $this->connectionTestTimeout, $this->connectionTestConnectTimeout);

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
                'metadata' => method_exists($e, 'context') ? $e->context() : [],
            ];
        }
    }

    public function fetchDevices(array $options = []): array
    {
        $limit   = max(1, min((int) ($options['limit'] ?? 500), 500));
        $offset  = (int) ($options['cursor'] ?? 0);
        $filters = $options['filters'] ?? new \stdClass();

        if (is_array($filters) && empty($filters)) {
            $filters = new \stdClass();
        }

        $response = $this->afaqyPost('/units/lists', [
            'data' => [
                'address'    => $options['address'] ?? false,
                'filters'    => $filters,
                'limit'      => $limit,
                'offset'     => $offset,
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
                    'sensors_last_val',
                ],
            ],
        ], true);

        $devices     = $response['data'] ?? [];
        $pagination  = $response['pagination'] ?? [];
        $pageOffset  = (int) ($pagination['offset'] ?? $offset);
        $pageLimit   = (int) ($pagination['limit'] ?? $limit);
        $resultCount = (int) ($pagination['resultCount'] ?? count($devices));
        $total       = (int) ($pagination['filtersCount'] ?? $pagination['allCount'] ?? count($devices));
        $advanceBy   = $resultCount > 0 ? $resultCount : max($pageLimit, count($devices), 1);
        $nextCursor  = ($pageOffset + $advanceBy) < $total ? $pageOffset + $advanceBy : null;

        Log::info('AFAQY units page fetched', [
            'telematic_uuid'        => $this->telematic?->uuid,
            'requested_offset'      => $offset,
            'requested_limit'       => $limit,
            'provider_offset'       => $pageOffset,
            'provider_limit'        => $pageLimit,
            'provider_result_count' => $resultCount,
            'result_count'          => count($devices),
            'all_count'             => $pagination['allCount'] ?? null,
            'filters_count'         => $pagination['filtersCount'] ?? null,
            'next_cursor'           => $nextCursor,
            'has_more'              => $nextCursor !== null,
        ]);

        return [
            'devices'     => $devices,
            'next_cursor' => $nextCursor,
            'has_more'    => $nextCursor !== null,
            'pagination'  => [
                'allCount'     => $pagination['allCount'] ?? null,
                'filtersCount' => $pagination['filtersCount'] ?? null,
                'resultCount'  => $resultCount,
                'offset'       => $pageOffset,
                'limit'        => $pageLimit,
            ],
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
        $profile    = $payload['profile'] ?? [];

        return [
            'device_id'    => $payload['_id'] ?? $payload['id'] ?? null,
            'external_id'  => $payload['_id'] ?? $payload['id'] ?? null,
            'name'         => $payload['name'] ?? data_get($payload, 'profile.plate_number') ?? 'Unknown Unit',
            'provider'     => 'afaqy',
            'model'        => data_get($payload, 'profile.model') ?? $payload['device'] ?? null,
            'imei'         => $payload['imei'] ?? null,
            'phone'        => $payload['sim_number'] ?? null,
            'vin'          => data_get($payload, 'profile.vin'),
            'status'       => ($payload['active'] ?? false) ? 'active' : 'inactive',
            'online'       => $payload['active'] ?? null,
            'last_seen_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? $lastUpdate['dts'] ?? null),
            'location'     => [
                'lat' => $lastUpdate['lat'] ?? null,
                'lng' => $lastUpdate['lng'] ?? null,
            ],
            'meta' => [
                'provider_unit_id' => $payload['_id'] ?? $payload['id'] ?? null,
                'plate_number'     => data_get($profile, 'plate_number'),
                'vehicle_type'     => data_get($profile, 'vehicle_type'),
                'fuel_type'        => data_get($profile, 'fuel_type'),
                'device_serial'    => $payload['device_serial'] ?? null,
                'sim_number'       => $payload['sim_number'] ?? null,
                'driver_id'        => $payload['driver_id'] ?? null,
                'last_update'      => $this->compactLastUpdate($lastUpdate),
                'capabilities'     => [
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
            'message'     => $payload['message'] ?? $payload['event'] ?? null,
            'occurred_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? $lastUpdate['dts'] ?? null),
            'online'      => $payload['active'] ?? null,
            'location'    => [
                'lat' => $lastUpdate['lat'] ?? data_get($lastUpdate, 'loc.coordinates.1'),
                'lng' => $lastUpdate['lng'] ?? data_get($lastUpdate, 'loc.coordinates.0'),
            ],
            'speed'      => $lastUpdate['speed'] ?? $lastUpdate['spd'] ?? null,
            'heading'    => $lastUpdate['angle'] ?? $lastUpdate['ang'] ?? null,
            'altitude'   => $lastUpdate['alt'] ?? null,
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
                'name'          => 'base_url',
                'label'         => 'Base URL',
                'type'          => 'text',
                'placeholder'   => 'https://api.afaqy.sa',
                'required'      => false,
                'advanced'      => true,
                'is_endpoint'   => true,
                'default_value' => 'https://api.afaqy.sa',
                'help_text'     => 'Optional override. Leave blank to use the default AFAQY API host.',
                'validation'    => 'nullable|url',
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
            throw new TelematicProviderException('AFAQY authentication failed with status ' . $response->status(), $this->providerErrorContext('/auth/login', $response, false));
        }

        $token = data_get($response->json(), 'data.token');

        if (!$token) {
            throw new TelematicProviderException('AFAQY authentication did not return a token.', $this->providerErrorContext('/auth/login', $response, false));
        }

        return $token;
    }

    protected function afaqyPost(string $endpoint, array $payload = [], bool $tokenInQuery = false, ?int $timeout = null, ?int $connectTimeout = null): array
    {
        return $this->authenticatedPost($endpoint, $payload, $tokenInQuery, true, $timeout, $connectTimeout);
    }

    protected function authenticatedPost(string $endpoint, array $payload = [], bool $tokenInQuery = false, bool $allowRetry = true, ?int $timeout = null, ?int $connectTimeout = null): array
    {
        [$url, $body] = $this->buildAuthenticatedRequest($endpoint, $payload, $tokenInQuery);

        $startedAt = microtime(true);
        $timeout ??= $this->dataTimeout;
        $connectTimeout ??= $this->connectTimeout;

        try {
            $response = Http::withHeaders($this->headers)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new TelematicProviderException('AFAQY API request timed out while waiting for provider response.', $this->transportErrorContext($endpoint, $payload, $e, !$allowRetry, $startedAt, $timeout, $connectTimeout), previous: $e);
        }

        if ($this->isTokenRejected($response)) {
            if (!$allowRetry || !$this->canRefreshToken()) {
                $message = $this->canRefreshToken()
                    ? 'AFAQY token rejected after refresh with status ' . $response->status()
                    : 'AFAQY token rejected and username/password credentials are required to refresh it.';

                throw new TelematicProviderException($message, $this->providerErrorContext($endpoint, $response, !$allowRetry));
            }

            Log::warning('AFAQY token rejected; refreshing token and retrying request', $this->providerErrorContext($endpoint, $response, true));

            $this->refreshToken();

            return $this->authenticatedPost($endpoint, $payload, $tokenInQuery, false, $timeout, $connectTimeout);
        }

        if ($response->failed()) {
            throw new TelematicProviderException('AFAQY API request failed with status ' . $response->status(), $this->providerErrorContext($endpoint, $response, !$allowRetry));
        }

        return $response->json() ?? [];
    }

    protected function buildAuthenticatedRequest(string $endpoint, array $payload = [], bool $tokenInQuery = false): array
    {
        $url  = $this->baseUrl . $endpoint;
        $body = $tokenInQuery ? $payload : array_merge(['token' => $this->credentials['token']], $payload);

        if ($tokenInQuery) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['token' => $this->credentials['token']]);
        }

        return [$url, $body];
    }

    protected function setToken(string $token): void
    {
        $this->credentials['token'] = $token;
        $this->headers              = [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    protected function refreshToken(): void
    {
        unset($this->credentials['token']);

        $this->setToken($this->authenticate());
    }

    protected function canRefreshToken(): bool
    {
        return !empty($this->credentials['username']) && !empty($this->credentials['password']);
    }

    protected function isTokenRejected(Response $response): bool
    {
        return in_array($response->status(), [401, 403], true);
    }

    protected function providerErrorContext(string $endpoint, Response $response, bool $retryAttempted): array
    {
        $json = $response->json() ?? [];
        $json = is_array($json) ? $json : [];

        return array_filter([
            'provider'         => 'afaqy',
            'endpoint'         => $endpoint,
            'status_code'      => $response->status(),
            'provider_code'    => data_get($json, 'code') ?? data_get($json, 'error.code') ?? data_get($json, 'error_code'),
            'provider_message' => $this->providerErrorMessage($json),
            'retry_attempted'  => $retryAttempted,
        ], fn ($value) => $value !== null);
    }

    protected function providerErrorMessage(array $json): ?string
    {
        $message = data_get($json, 'message')
            ?? data_get($json, 'error.message')
            ?? data_get($json, 'error_description')
            ?? data_get($json, 'error');

        return is_scalar($message) ? (string) $message : null;
    }

    protected function transportErrorContext(string $endpoint, array $payload, ConnectionException $e, bool $retryAttempted, float $startedAt, int $timeout, int $connectTimeout): array
    {
        return array_filter([
            'provider'         => 'afaqy',
            'endpoint'         => $endpoint,
            'requested_limit'  => data_get($payload, 'data.limit'),
            'requested_offset' => data_get($payload, 'data.offset'),
            'timeout'          => $timeout,
            'connect_timeout'  => $connectTimeout,
            'elapsed_ms'       => (int) ((microtime(true) - $startedAt) * 1000),
            'bytes_received'   => $this->extractBytesReceived($e->getMessage()),
            'retry_attempted'  => $retryAttempted,
            'transport_error'  => 'connection_exception',
        ], fn ($value) => $value !== null);
    }

    protected function extractBytesReceived(string $message): ?int
    {
        if (preg_match('/with\s+(\d+)\s+bytes\s+received/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    protected function compactLastUpdate(array $lastUpdate): array
    {
        $params = $lastUpdate['params'] ?? $lastUpdate['prms'] ?? [];

        return array_filter([
            'occurred_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? $lastUpdate['dts'] ?? null),
            'lat'         => $lastUpdate['lat'] ?? null,
            'lng'         => $lastUpdate['lng'] ?? null,
            'speed'       => $lastUpdate['speed'] ?? $lastUpdate['spd'] ?? null,
            'heading'     => $lastUpdate['angle'] ?? $lastUpdate['ang'] ?? null,
            'altitude'    => $lastUpdate['alt'] ?? null,
            'satellites'  => $params['sat'] ?? null,
            'protocol'    => $params['protocol'] ?? null,
        ], fn ($value) => $value !== null);
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
