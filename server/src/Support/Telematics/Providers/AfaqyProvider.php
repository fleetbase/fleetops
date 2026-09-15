<?php

namespace Fleetbase\FleetOps\Support\Telematics\Providers;

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Support\Telematics\Afaqy\Payload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        $token         = $this->canRefreshToken() ? $this->cachedToken() : ($this->credentials['token'] ?? null);

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
                    'offset'     => 0, 'limit' => 1, 'simplify' => 0,
                    'projection' => ['basic', 'last_update'],
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
        $limit   = max(1, min((int) ($options['limit'] ?? 1000), 1000));
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
                'simplify'   => 0,
                'projection' => $options['projection'] ?? ['basic', 'last_update'],
            ],
        ], true, $options['timeout'] ?? null, $options['connect_timeout'] ?? null);

        if (!isset($response['data']) || !is_array($response['data']) || !array_is_list($response['data'])) {
            throw new TelematicProviderException('AFAQY returned an invalid units envelope.');
        }
        $devices = array_map(function ($unit) {
            if (!is_array($unit)) {
                throw new TelematicProviderException('AFAQY returned an invalid unit.');
            }

            return Payload::unit($unit);
        }, $response['data']);
        $pagination  = $response['pagination'] ?? [];
        $pageOffset  = (int) ($pagination['offset'] ?? $offset);
        $pageLimit   = (int) ($pagination['limit'] ?? $limit);
        $resultCount = (int) ($pagination['resultCount'] ?? count($devices));
        $total       = $pagination['filtersCount'] ?? $pagination['allCount'] ?? null;
        if ($resultCount !== count($devices) || $pageLimit < 1 || ($total !== null && (int) $total < 0)) {
            throw new TelematicProviderException('AFAQY returned inconsistent pagination; sweep incomplete.');
        }
        $advanceBy = count($devices);
        if ($pageOffset !== $offset || ($devices === [] && $total !== null && $offset < (int) $total)) {
            throw new TelematicProviderException('AFAQY pagination did not advance; sweep incomplete.');
        }
        $nextCursor = $total !== null
            ? (($pageOffset + $advanceBy) < (int) $total ? $pageOffset + $advanceBy : null)
            : (count($devices) >= max(1, $pageLimit) ? $pageOffset + count($devices) : null);

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
        $payload    = Payload::unit($payload);
        $lastUpdate = $payload['last_update'] ?? [];
        $profile    = $payload['profile'] ?? [];

        return [
            'device_id'    => $payload['_id'] ?? $payload['id'] ?? null,
            'external_id'  => $payload['_id'] ?? $payload['id'] ?? null,
            'name'         => $payload['name'] ?? data_get($payload, 'profile.plate_number') ?? null,
            'provider'     => 'afaqy',
            'model'        => data_get($payload, 'profile.model') ?? $payload['device'] ?? null,
            'imei'         => $payload['imei'] ?? null,
            'phone'        => $payload['sim_number'] ?? null,
            'vin'          => data_get($payload, 'profile.vin'),
            'status'       => array_key_exists('active', $payload) ? ($payload['active'] ? 'active' : 'inactive') : null,
            'online'       => null,
            'last_seen_at' => $this->parseTimestamp($lastUpdate['dts'] ?? $lastUpdate['dtt'] ?? null),
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
                'capabilities'     => array_filter([
                    'tracking'       => isset($lastUpdate['lat'], $lastUpdate['lng']) ? true : null,
                    'odometer'       => data_get($payload, 'counters.odometer') !== null ? true : null,
                    'fuel_level'     => $this->extractFuelLevel($payload) !== null ? true : null,
                    'ignition_state' => $this->extractIgnition($payload) !== null ? true : null,
                ], fn ($value) => $value !== null),
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $payload    = Payload::unit($payload);
        $lastUpdate = $payload['last_update'] ?? $payload;

        return [
            'external_id' => $payload['_id'] ?? $payload['id'] ?? null,
            'device_id'   => $payload['_id'] ?? $payload['id'] ?? null,
            'event_type'  => $payload['event'] ?? $payload['event_type'] ?? 'telemetry_update',
            'message'     => $payload['message'] ?? $payload['event'] ?? null,
            'occurred_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? null),
            'online'      => null,
            'location'    => [
                'lat' => $lastUpdate['lat'] ?? data_get($lastUpdate, 'loc.coordinates.1'),
                'lng' => $lastUpdate['lng'] ?? data_get($lastUpdate, 'loc.coordinates.0'),
            ],
            'speed'        => $lastUpdate['speed'] ?? $lastUpdate['spd'] ?? null,
            'heading'      => $lastUpdate['angle'] ?? $lastUpdate['ang'] ?? null,
            'altitude'     => $lastUpdate['alt'] ?? null,
            'odometer'     => data_get($payload, 'counters.odometer'),
            'ignition'     => $this->extractIgnition($payload),
            'fuel_level'   => $this->extractFuelLevel($payload),
            'last_seen_at' => $this->parseTimestamp($lastUpdate['dts'] ?? $lastUpdate['dtt'] ?? null),
            'meta'         => array_merge($payload, ['afaqy' => [
                'position_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? null),
                'provider_at' => $this->parseTimestamp($lastUpdate['dts'] ?? null),
            ]]),
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        $unitId         = $payload['device_id'] ?? $payload['unit_id'] ?? $payload['vehicle_id'] ?? null;
        $sensorIdentity = $this->resolveSensorIdentity($payload);
        $sensorName     = $this->resolveSensorName($payload, $sensorIdentity);
        $value          = $this->resolveSensorValue($payload);

        if (!$unitId || !$sensorIdentity || !$sensorName || !$this->isSensorValue($value)) {
            throw new \InvalidArgumentException('AFAQY sensor payload does not contain a stable identity and scalar latest value.');
        }

        $type       = $this->normalizeSensorType($payload, $sensorName);
        $internalId = implode(':', ['afaqy', $unitId, $this->sensorIdentityPart($sensorIdentity)]);

        return [
            'internal_id' => $internalId,
            'external_id' => $internalId,
            'name'        => $sensorName,
            'type'        => $type,
            'sensor_type' => $type,
            'value'       => $value,
            'unit'        => $payload['unit'] ?? $payload['units'] ?? data_get($payload, 'last_val.unit'),
            'recorded_at' => $this->parseTimestamp(data_get($payload, 'last_val.dtt') ?? data_get($payload, 'last_update.dtt') ?? $payload['updated_at'] ?? $payload['created_at'] ?? null),
            'status'      => 'active',
            'meta'        => array_filter([
                'provider'           => 'afaqy',
                'unit_id'            => $unitId,
                'provider_sensor_id' => $sensorIdentity,
                'sensor_key'         => $payload['sensor_key'] ?? null,
                'raw'                => $payload,
            ], fn ($value) => $value !== null),
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

        $this->reserveRequest();
        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post($this->baseUrl . '/auth/login', [
                'data' => [
                    'username' => $this->credentials['username'],
                    'password' => $this->credentials['password'],
                ],
            ]);

        $this->checkThrottle($response);
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

        $this->reserveRequest();
        try {
            $response = Http::withHeaders($this->headers)
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new TelematicProviderException('AFAQY API request timed out while waiting for provider response.', $this->transportErrorContext($endpoint, $payload, $e, !$allowRetry, $startedAt, $timeout, $connectTimeout), previous: $e);
        }

        $this->checkThrottle($response);
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

        $json = $response->json();
        if (!is_array($json) || (isset($json['status_code']) && (int) $json['status_code'] >= 400)) {
            throw new TelematicProviderException('AFAQY returned an invalid or unsuccessful response.');
        }

        return $json;
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
        $rejected = $this->credentials['token'] ?? null;
        $this->setToken($this->cachedToken($rejected));
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function processWebhook(array $payload, array $headers = []): array
    {
        $units = Payload::units($payload);

        return ['devices' => array_map([$this, 'normalizeDevice'], $units), 'events' => array_map([$this, 'normalizeEvent'], $units), 'sensors' => []];
    }

    protected function accountKey(): string
    {
        return hash('sha256', $this->baseUrl . '|' . ($this->credentials['username'] ?? $this->credentials['token'] ?? ''));
    }

    protected function cachedToken(?string $rejected = null): string
    {
        $key = 'afaqy:token:' . $this->accountKey() . ':' . hash('sha256', $this->credentials['password'] ?? '');

        return Cache::lock($key . ':lock', 40)->block(5, function () use ($key, $rejected) {
            $stored = Cache::get($key);
            $token  = $stored ? Crypt::decryptString($stored) : null;
            if (!$token || $token === $rejected) {
                $token = $this->authenticate();
                Cache::put($key, Crypt::encryptString($token), 29 * 86400);
            }

            return $token;
        });
    }

    protected function reserveRequest(): void
    {
        $key = 'afaqy:rate:' . $this->accountKey();
        Cache::lock($key . ':lock', 5)->block(2, function () use ($key) {
            $now      = microtime(true);
            $until    = (float) Cache::get($key . ':blocked', 0);
            $requests = array_values(array_filter(Cache::get($key, []), fn ($at) => $at > $now - 60));
            if ($until > $now || count($requests) >= 60) {
                throw new TelematicRateLimitExceededException('AFAQY request budget exhausted.', ['retry_after' => max(1, (int) ceil(max($until, ($requests[0] ?? $now) + 60) - $now))]);
            }
            $requests[] = $now;
            Cache::put($key, $requests, 61);
        });
    }

    protected function checkThrottle(Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }
        $header = $response->header('Retry-After');
        $delay  = is_numeric($header) ? (int) $header : max(1, (strtotime($header ?: '') ?: time() + 60) - time());
        $delay  = max(1, min($delay, 3600));
        Cache::put('afaqy:rate:' . $this->accountKey() . ':blocked', microtime(true) + $delay, $delay);
        throw new TelematicRateLimitExceededException('AFAQY rate limited the request.', ['retry_after' => $delay]);
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

        if (!is_scalar($message)) {
            return null;
        }
        $message = (string) $message;
        foreach (['token', 'password'] as $secret) {
            if (!empty($this->credentials[$secret])) {
                $message = str_replace($this->credentials[$secret], '[redacted]', $message);
            }
        }

        return preg_replace('/(token|password|key)=([^&\s]+)/i', '$1=[redacted]', $message);
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
            'occurred_at' => $this->parseTimestamp($lastUpdate['dtt'] ?? null),
            'provider_at' => $this->parseTimestamp($lastUpdate['dts'] ?? null),
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
        return Payload::timestamp($value);
    }

    protected function extractIgnition(array $payload): ?bool
    {
        $params = data_get($payload, 'last_update.params', []);
        $value  = data_get($payload, 'last_update.acc') ?? data_get($params, 'acc') ?? data_get($params, 'di1') ?? data_get($payload, 'counters.last_acc');

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

    protected function resolveSensorIdentity(array $payload): ?string
    {
        $value = $payload['_id']
            ?? $payload['id']
            ?? $payload['sensor_id']
            ?? $payload['ident']
            ?? $payload['param']
            ?? $payload['sensor_key']
            ?? $payload['name']
            ?? data_get($payload, 'sensor.name');

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    protected function resolveSensorName(array $payload, ?string $fallback = null): ?string
    {
        $value = $payload['name']
            ?? data_get($payload, 'sensor.name')
            ?? $payload['param']
            ?? $payload['sensor_key']
            ?? $fallback;

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    protected function resolveSensorValue(array $payload): mixed
    {
        if (array_key_exists('value', $payload)) {
            return $payload['value'];
        }

        return data_get($payload, 'last_val.value')
            ?? data_get($payload, 'last_update.value')
            ?? data_get($payload, 'last_update.val')
            ?? data_get($payload, 'last_update.state');
    }

    protected function isSensorValue(mixed $value): bool
    {
        return $value !== null && is_scalar($value);
    }

    protected function normalizeSensorType(array $payload, string $sensorName): string
    {
        $providerType = (string) ($payload['sensor_type'] ?? $payload['type'] ?? $payload['param'] ?? '');
        $type         = strtolower(trim($providerType . ' ' . $sensorName));

        return match (true) {
            str_contains($type, 'door')                                      => 'door',
            str_contains($type, 'temp')                                      => 'temperature',
            str_contains($type, 'humid')                                     => 'humidity',
            str_contains($type, 'fuel')                                      => 'fuel',
            str_starts_with($type, 'di') || str_contains($type, 'digital')   => 'digital',
            str_starts_with($type, 'ai') || str_contains($type, 'analog')    => 'analog',
            str_contains($type, 'battery') || str_contains($type, 'voltage') => 'voltage',
            default                                                          => $this->sensorIdentityPart($type) ?: 'generic',
        };
    }

    protected function sensorIdentityPart(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : 'sensor';
    }
}
