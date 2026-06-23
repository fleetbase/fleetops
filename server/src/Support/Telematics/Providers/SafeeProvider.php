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
    protected string $baseUrl        = 'https://api.safee.com';
    protected int $requestsPerMinute = 3000;
    protected ?string $accessToken   = null;
    protected array $authContext     = [];
    protected int $dataTimeout       = 120;
    protected int $connectTimeout    = 15;

    protected function prepareAuthentication(): void
    {
        $this->baseUrl     = $this->resolveBaseUrl();
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
                    ...$this->safeDiagnosticMetadata(),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'metadata' => $this->safeDiagnosticMetadata(),
            ];
        }
    }

    public function fetchDevices(array $options = []): array
    {
        $listInfoBody  = $this->resolveListInfoPayload($options);
        $response      = $this->safeePost('/api/v2/vehicle/list-info', $listInfoBody, true);
        $vehicles      = $response['result'] ?? [];
        $vehicleIds    = $this->resolveListedVehicleIds($vehicles);
        $identityStats = $this->summarizeVehicleIdentities($vehicles, $vehicleIds);
        $endpointStats = [
            'vehicles_listed'                 => count($vehicles),
            'unique_vehicle_ids'              => $identityStats['unique_vehicle_ids'],
            'missing_vehicle_ids'             => $identityStats['missing_vehicle_ids'],
            'duplicate_vehicle_ids'           => $identityStats['duplicate_vehicle_ids'],
            'list_info_page_size'             => $listInfoBody['pageSize'] ?? null,
            'list_info_requested_unpaginated' => ($listInfoBody['pageSize'] ?? null) === 0,
            'last_state_fetched'              => 0,
            'last_info_fetched'               => 0,
            'positions_fetched'               => 0,
            'events_fetched'                  => 0,
            'devices_returned_for_ingestion'  => count($vehicles),
            'failures'                        => [],
        ];

        $devices = array_map(function (array $vehicle) use (&$endpointStats) {
            $vehicleId = $this->resolveListedVehicleId($vehicle);

            return array_merge($vehicle, [
                '_safee' => [
                    'vehicle_id'     => $vehicleId,
                    'identity'      => $vehicle,
                    'current_info'  => null,
                    'current_state' => null,
                    'positions'     => [],
                    'events'        => [],
                    'sync_window'   => null,
                    'diagnostics'   => $endpointStats,
                ],
                'sensors' => [],
            ]);
        }, $vehicles);

        return [
            'devices'     => $devices,
            'next_cursor' => null,
            'has_more'    => false,
            'sync_meta'   => [
                'safee_last_endpoint_counts'     => array_merge($endpointStats, [
                    'failures' => array_slice($endpointStats['failures'], 0, 25),
                ]),
            ],
        ];
    }

    public function fetchDeviceTelemetrySnapshots(array $inventoryPayloads, array $options = []): array
    {
        $window        = $this->resolveTelemetryWindow($options);
        $vehicleIds    = $this->resolveListedVehicleIds($inventoryPayloads);
        $identityStats = $this->summarizeVehicleIdentities($inventoryPayloads, $vehicleIds);
        $statesById    = $this->fetchLastStatesByVehicle($vehicleIds);
        $endpointStats = [
            'vehicles_listed'                 => count($inventoryPayloads),
            'unique_vehicle_ids'              => $identityStats['unique_vehicle_ids'],
            'missing_vehicle_ids'             => $identityStats['missing_vehicle_ids'],
            'duplicate_vehicle_ids'           => $identityStats['duplicate_vehicle_ids'],
            'last_state_fetched'              => count($statesById),
            'last_info_fetched'               => 0,
            'positions_fetched'               => 0,
            'events_fetched'                  => 0,
            'devices_returned_for_ingestion'  => count($inventoryPayloads),
            'failures'                        => [],
        ];

        $devices = array_map(function (array $vehicle) use ($statesById, $window, &$endpointStats) {
            return $this->enrichVehicleSnapshot($vehicle, $statesById, $window, $endpointStats);
        }, $inventoryPayloads);

        return [
            'devices'   => $devices,
            'sync_meta' => [
                'safee_last_telemetry_synced_at' => $window['endDate'],
                'safee_last_sync_window'         => $window,
                'safee_last_endpoint_counts'     => array_merge($endpointStats, [
                    'failures' => array_slice($endpointStats['failures'], 0, 25),
                ]),
                'safee_last_enrichment_total'     => count($inventoryPayloads),
                'safee_last_enrichment_completed' => count($devices),
                'safee_last_enrichment_failures'  => array_slice($endpointStats['failures'], 0, 25),
            ],
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
        $identity  = $this->identityPayload($payload);
        $current   = $this->currentTelemetryPayload($payload) ?? [];
        $vehicleId = $this->resolveCanonicalVehicleId($payload, $identity, $current);
        $position  = $this->extractPosition($current ?: $payload);
        $rawStatus = $current['status'] ?? $identity['status'] ?? $identity['vehicleStatus'] ?? null;
        $status    = $this->normalizeVehicleStatus($rawStatus);

        return [
            'device_id'     => $vehicleId,
            'external_id'   => $vehicleId,
            'name'          => $this->resolveVehicleName($identity, $current, $vehicleId),
            'provider'      => 'safee',
            'model'         => $identity['model'] ?? data_get($identity, 'device.model') ?? null,
            'internal_id'   => $identity['uuid'] ?? $vehicleId,
            'imei'          => data_get($identity, 'device.imei') ?? data_get($current, 'device.imei') ?? null,
            'serial_number' => data_get($identity, 'device.serial') ?? data_get($identity, 'device.id') ?? data_get($current, 'device.serial') ?? null,
            'vin'           => $identity['vin'] ?? null,
            'status'        => $status,
            'online'        => $this->resolveOnline($current ?: $identity),
            'last_seen_at'  => $this->parseTimestamp($current['date'] ?? $current['deviceTime'] ?? $current['time'] ?? null),
            'location'      => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'speed'      => $current['speed'] ?? $current['lastSpeed'] ?? null,
            'heading'    => $current['heading'] ?? $current['angle'] ?? null,
            'altitude'   => $position['alt'] ?? $current['altitude'] ?? $current['alt'] ?? null,
            'odometer'   => $this->extractOdometer($current),
            'ignition'   => $this->extractIgnition($current),
            'fuel_level' => $this->extractFuelLevel($current),
            'meta'       => [
                'raw'             => $payload,
                'provider_status' => array_filter([
                    'status'        => $rawStatus,
                    'normalized'    => $status,
                    'vehicleStatus' => $identity['vehicleStatus'] ?? null,
                ], fn ($value) => $value !== null),
                'plate_number' => $identity['plateNo'] ?? $identity['plateNumber'] ?? $identity['plate_number'] ?? $current['plateNo'] ?? null,
                'driver'       => $current['driver'] ?? $identity['driver'] ?? null,
                'temperature'  => $current['temperature'] ?? null,
                'door'         => $current['door'] ?? null,
                'humidity'     => $current['humidity'] ?? $current['humidityPerType'] ?? null,
                'mileage'      => $current['mileage'] ?? null,
                'last_update'  => $current ? $this->normalizeEvent($payload) : null,
                'safee'        => [
                    'vehicle_id'   => $vehicleId,
                    'vehicle_uuid' => $identity['uuid'] ?? null,
                    'sync_window'  => data_get($payload, '_safee.sync_window'),
                    'diagnostics'  => data_get($payload, '_safee.diagnostics'),
                ],
                'capabilities' => [
                    'tracking'       => isset($position['lat'], $position['lng']),
                    'odometer'       => $this->extractOdometer($current) !== null,
                    'fuel_level'     => $this->extractFuelLevel($current) !== null,
                    'ignition_state' => $this->extractIgnition($current) !== null,
                ],
            ],
        ];
    }

    public function normalizeEvent(array $payload): array
    {
        $eventPayload = $this->currentTelemetryPayload($payload) ?? $payload;

        return $this->normalizeSafeeTelemetryEvent($eventPayload, 'current', $this->identityPayload($payload));
    }

    public function normalizeEvents(array $payload): array
    {
        $identity = $this->identityPayload($payload);
        $events   = [];

        if ($current = $this->currentTelemetryPayload($payload)) {
            $events[] = $this->normalizeSafeeTelemetryEvent($current, 'current', $identity);
        }

        foreach ((array) data_get($payload, '_safee.positions', []) as $position) {
            if (is_array($position)) {
                $events[] = $this->normalizeSafeeTelemetryEvent($position, 'position', $identity);
            }
        }

        foreach ((array) data_get($payload, '_safee.events', []) as $event) {
            if (is_array($event)) {
                $events[] = $this->normalizeSafeeTelemetryEvent($event, 'event', $identity);
            }
        }

        return $events;
    }

    protected function normalizeSafeeTelemetryEvent(array $payload, string $source, array $identity = []): array
    {
        $position        = $this->extractPosition($payload);
        $vehicleId       = $this->resolveVehicleId($identity) ?? $this->resolveVehicleId($payload);
        $eventCode       = data_get($payload, 'event.code') ?? data_get($payload, 'type.key') ?? data_get($payload, 'event.name');
        $eventName       = data_get($payload, 'event.name') ?? data_get($payload, 'type.value') ?? $payload['reason'] ?? null;
        $occurredAt      = $this->parseTimestamp($payload['date'] ?? $payload['deviceTime'] ?? $payload['time'] ?? null);
        $providerEventId = $source === 'current'
            ? (data_get($payload, 'event.id') ?? $payload['id'] ?? null)
            : ($payload['id'] ?? data_get($payload, 'event.id') ?? null);

        return [
            'external_id'       => implode(':', array_filter(['safee', $source, $vehicleId, $providerEventId, $payload['date'] ?? null])),
            'external_event_id' => $providerEventId,
            'device_id'         => $vehicleId,
            'event_type'        => $eventCode ?? ($source === 'position' ? 'position_update' : 'telemetry_update'),
            'code'              => $eventCode,
            'message'           => $eventName,
            'reason'            => $payload['reason'] ?? null,
            'state'             => $payload['status'] ?? null,
            'occurred_at'       => $occurredAt,
            'online'            => $this->resolveOnline($payload),
            'location'          => [
                'lat' => $position['lat'] ?? null,
                'lng' => $position['lng'] ?? null,
            ],
            'speed'      => $payload['speed'] ?? $payload['lastSpeed'] ?? null,
            'heading'    => $payload['heading'] ?? $payload['angle'] ?? null,
            'altitude'   => $position['alt'] ?? $payload['altitude'] ?? $payload['alt'] ?? null,
            'odometer'   => $this->extractOdometer($payload),
            'ignition'   => $this->extractIgnition($payload),
            'fuel_level' => $this->extractFuelLevel($payload),
            'data'       => array_filter([
                'source'      => $source,
                'status'      => $payload['status'] ?? null,
                'type'        => $payload['type'] ?? null,
                'event'       => $payload['event'] ?? null,
                'driver'      => $payload['driver'] ?? $identity['driver'] ?? null,
                'vehicle'     => $payload['vehicle'] ?? null,
                'arguments'   => $payload['arguments'] ?? null,
                'temperature' => $payload['temperature'] ?? null,
                'door'        => $payload['door'] ?? null,
                'humidity'    => $payload['humidity'] ?? $payload['humidityPerType'] ?? null,
                'mileage'     => $payload['mileage'] ?? null,
            ], fn ($value) => $value !== null),
            'meta'       => [
                'raw'          => $payload,
                'source'       => $source,
                'plate_number' => $payload['plateNo'] ?? $identity['plateNo'] ?? null,
            ],
        ];
    }

    public function normalizeSensor(array $payload): array
    {
        $type = $payload['type'] ?? $payload['sensor_type'] ?? 'generic';
        $name = $payload['name'] ?? $payload['sensor_name'] ?? $type;

        return [
            'internal_id' => $payload['internal_id'] ?? $payload['sensor_id'] ?? $payload['external_id'] ?? null,
            'external_id' => $payload['external_id'] ?? $payload['internal_id'] ?? null,
            'name'        => $name,
            'type'        => $type,
            'sensor_type' => $type,
            'value'       => $payload['value'] ?? $payload['lastValue'] ?? null,
            'unit'        => $payload['unit'] ?? null,
            'recorded_at' => $this->parseTimestamp($payload['recorded_at'] ?? $payload['date'] ?? $payload['deviceTime'] ?? null),
            'status'      => $payload['status'] ?? 'active',
            'meta'        => array_filter(array_merge($payload['meta'] ?? [], [
                'provider'   => 'safee',
                'vehicle_id' => $payload['vehicle_id'] ?? null,
                'plate_no'   => $payload['plate_no'] ?? null,
                'source'     => $payload['source'] ?? null,
                'raw'        => $payload['raw'] ?? $payload,
            ]), fn ($value) => $value !== null),
        ];
    }

    public function getCredentialSchema(): array
    {
        return [
            [
                'name'          => 'server_uri',
                'label'         => 'Server URI',
                'type'          => 'text',
                'placeholder'   => 'https://api.safee.com',
                'required'      => false,
                'advanced'      => true,
                'is_endpoint'   => true,
                'default_value' => 'https://api.safee.com',
                'help_text'     => 'Optional override. Leave blank to use the default Safee API host.',
                'validation'    => 'nullable|url',
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
        foreach (['realm_id', 'client_id', 'client_secret', 'username', 'password'] as $field) {
            if (empty($this->credentials[$field])) {
                throw new \InvalidArgumentException("Safee credential '{$field}' is required.");
            }
        }

        $tokenUrl          = $this->baseUrl . '/auth/realms/' . $this->credentials['realm_id'] . '/protocol/openid-connect/token';
        $this->authContext = $this->buildAuthContext($tokenUrl);

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
            throw new \RuntimeException('Safee authentication failed with status ' . $response->status());
        }

        $token = $response->json('access_token');

        if (!$token) {
            throw new \RuntimeException('Safee authentication did not return an access token.');
        }

        return $token;
    }

    protected function resolveBaseUrl(): string
    {
        $baseUrl = $this->filledCredential('api_base_url') ?? $this->filledCredential('server_uri') ?? $this->baseUrl;

        return rtrim($baseUrl, '/');
    }

    protected function filledCredential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function buildAuthContext(string $tokenUrl): array
    {
        $parts  = parse_url($tokenUrl) ?: [];
        $scheme = $parts['scheme'] ?? null;
        $host   = $parts['host'] ?? null;

        return [
            'auth_host' => $host ? trim(($scheme ? $scheme . '://' : '') . $host, '/') : null,
            'auth_path' => $parts['path'] ?? null,
            'realm_id'  => $this->credentials['realm_id'] ?? null,
        ];
    }

    protected function safeDiagnosticMetadata(): array
    {
        return array_filter($this->authContext, fn ($value) => $value !== null && $value !== '');
    }

    protected function resolveTelemetryWindow(array $options = []): array
    {
        $endDate     = (float) ($options['end_date'] ?? $this->currentSafeeTimestamp());
        $storedStart = data_get($this->telematic?->meta ?? [], 'safee_last_telemetry_synced_at');
        $startDate   = $options['start_date'] ?? ($storedStart ? ((float) $storedStart - 120) : $endDate - 900);

        return [
            'startDate' => max(0, (float) $startDate),
            'endDate'   => $endDate,
        ];
    }

    protected function currentSafeeTimestamp(): float
    {
        $now = Carbon::now();

        return (float) $now->getTimestamp() + ((int) $now->format('u') / 1000000);
    }

    protected function resolveListInfoPayload(array $options = []): array
    {
        $filter = $options['filter'] ?? [];

        if ($filter instanceof \stdClass) {
            $filter = (array) $filter;
        }

        $payload             = is_array($filter) ? $filter : [];
        $payload['pageSize'] = array_key_exists('page_size', $options) ? (int) $options['page_size'] : 0;

        if (array_key_exists('page_index', $options)) {
            $payload['pageIndex'] = (int) $options['page_index'];
        }

        return $payload;
    }

    protected function resolveListedVehicleIds(array $vehicles): array
    {
        $ids = [];

        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }

            $vehicleId = $this->resolveListedVehicleId($vehicle);
            if ($vehicleId !== null && $vehicleId !== '') {
                $ids[] = $vehicleId;
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    protected function summarizeVehicleIdentities(array $vehicles, array $uniqueVehicleIds): array
    {
        $counts  = [];
        $missing = 0;

        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                $missing++;
                continue;
            }

            $vehicleId = $this->resolveListedVehicleId($vehicle);
            if ($vehicleId === null || $vehicleId === '') {
                $missing++;
                continue;
            }

            $key          = (string) $vehicleId;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $duplicates = [];
        foreach ($counts as $vehicleId => $count) {
            if ($count > 1) {
                $duplicates[$vehicleId] = $count;
            }
        }

        return [
            'unique_vehicle_ids'    => count($uniqueVehicleIds),
            'missing_vehicle_ids'   => $missing,
            'duplicate_vehicle_ids' => $duplicates,
        ];
    }

    protected function resolveListedVehicleId(array $vehicle): mixed
    {
        return data_get($vehicle, '_safee.vehicle_id')
            ?? data_get($vehicle, '_safee.identity.id')
            ?? ($vehicle['id'] ?? null);
    }

    protected function fetchLastStatesByVehicle(array $vehicleIds): array
    {
        if (empty($vehicleIds)) {
            return [];
        }

        $states = [];
        foreach (array_chunk($vehicleIds, 1000) as $chunk) {
            $response = $this->safeePost('/api/v2/vehicle/last-state', [
                'live'      => true,
                'startDate' => null,
                'endDate'   => null,
                'vehicles'  => array_values($chunk),
            ], true);

            foreach ($response['result'] ?? [] as $state) {
                if (!is_array($state)) {
                    continue;
                }

                $vehicleId = $this->resolveVehicleId($state);
                if ($vehicleId !== null) {
                    $states[(string) $vehicleId] = $state;
                }
            }
        }

        return $states;
    }

    protected function enrichVehicleSnapshot(array $vehicle, array $statesById, array $window, array &$endpointStats): array
    {
        $vehicleId = $this->resolveListedVehicleId($vehicle);

        $lastInfo  = $this->fetchVehicleEndpoint('/api/v2/vehicle/last-info', ['vehicleId' => $vehicleId], 'last-info', $vehicleId, $endpointStats);
        $positions = $this->fetchVehicleEndpoint('/api/v2/vehicle/positions', [
            'vehicleId' => $vehicleId,
            'startDate' => $window['startDate'],
            'endDate'   => $window['endDate'],
        ], 'positions', $vehicleId, $endpointStats, []);
        $events = $this->fetchVehicleEndpoint('/api/v2/vehicle/events', [
            'vehicleId' => $vehicleId,
            'startDate' => $window['startDate'],
            'endDate'   => $window['endDate'],
            'status'    => 'ALL',
        ], 'events', $vehicleId, $endpointStats, []);

        $currentState = $statesById[(string) $vehicleId] ?? null;

        return array_merge($vehicle, [
            '_safee' => [
                'vehicle_id'     => $vehicleId,
                'identity'      => $vehicle,
                'current_info'  => $lastInfo,
                'current_state' => $currentState,
                'positions'     => is_array($positions) ? $positions : [],
                'events'        => is_array($events) ? $events : [],
                'sync_window'   => $window,
                'diagnostics'   => $endpointStats,
            ],
            'sensors' => $this->extractTelemetrySensors($lastInfo ?? []),
        ]);
    }

    protected function fetchVehicleEndpoint(string $endpoint, array $payload, string $name, mixed $vehicleId, array &$endpointStats, mixed $default = null): mixed
    {
        if (!$vehicleId) {
            return $default;
        }

        try {
            $response = $this->safeePost($endpoint, $payload, true);
            $result   = $response['result'] ?? $default;

            if ($name === 'last-info' && $result) {
                $endpointStats['last_info_fetched']++;
            }

            if ($name === 'positions' && is_array($result)) {
                $endpointStats['positions_fetched'] += count($result);
            }

            if ($name === 'events' && is_array($result)) {
                $endpointStats['events_fetched'] += count($result);
            }

            return $result;
        } catch (\Throwable $e) {
            $endpointStats['failures'][] = [
                'endpoint'   => $endpoint,
                'vehicle_id' => $vehicleId,
                'message'    => $this->sanitizeProviderMessage($e->getMessage()),
            ];

            return $default;
        }
    }

    protected function identityPayload(array $payload): array
    {
        return data_get($payload, '_safee.identity') ?? $payload;
    }

    protected function currentTelemetryPayload(array $payload): ?array
    {
        $currentInfo  = data_get($payload, '_safee.current_info');
        $currentState = data_get($payload, '_safee.current_state');

        if (is_array($currentInfo) && !empty($currentInfo)) {
            return $currentInfo;
        }

        return is_array($currentState) && !empty($currentState) ? $currentState : null;
    }

    protected function resolveVehicleId(array $payload): mixed
    {
        return data_get($payload, '_safee.vehicle_id')
            ?? data_get($payload, '_safee.identity.id')
            ?? $payload['vehicleId']
            ?? data_get($payload, 'vehicle.id')
            ?? $payload['id'];
    }

    protected function resolveCanonicalVehicleId(array $payload, array $identity, array $current = []): mixed
    {
        return data_get($payload, '_safee.vehicle_id')
            ?? data_get($payload, '_safee.identity.id')
            ?? data_get($identity, '_safee.vehicle_id')
            ?? data_get($identity, '_safee.identity.id')
            ?? ($identity['id'] ?? null)
            ?? $this->resolveVehicleId($current);
    }

    protected function resolveVehicleName(array $identity, array $current = [], mixed $vehicleId = null): string
    {
        return $identity['plateNo']
            ?? $identity['plateNumber']
            ?? $identity['name']
            ?? $current['plateNo']
            ?? data_get($current, 'vehicle.name')
            ?? ($vehicleId ? 'Safee Vehicle ' . $vehicleId : 'Unknown Safee Vehicle');
    }

    protected function safeeGet(string $endpoint): array
    {
        $response = Http::withHeaders($this->headers)
            ->timeout(30)
            ->get($this->baseUrl . $endpoint);

        if ($response->failed()) {
            throw new \RuntimeException('Safee API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
    }

    protected function safeePost(string $endpoint, array|\stdClass $payload = [], bool $dataEndpoint = false): array
    {
        $timeout        = $dataEndpoint ? $this->dataTimeout : 30;
        $connectTimeout = $dataEndpoint ? $this->connectTimeout : 10;
        $response       = Http::withHeaders($this->headers)
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->post($this->baseUrl . $endpoint, $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Safee API request failed with status ' . $response->status());
        }

        return $response->json() ?? [];
    }

    protected function sanitizeProviderMessage(string $message): string
    {
        return preg_replace('/(access_token|refresh_token|token|password|client_secret)=([^\\s&]+)/i', '$1=[redacted]', $message) ?? 'Safee API request failed';
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

    protected function resolveOnline(array $payload): ?bool
    {
        if (array_key_exists('online', $payload)) {
            return filter_var($payload['online'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $payload['online'];
        }

        $status = $payload['status'] ?? $payload['vehicleStatus'] ?? null;

        return $status ? $this->normalizeVehicleStatus($status) === 'active' : null;
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

    protected function extractTelemetrySensors(array $payload): array
    {
        $vehicleId = $this->resolveVehicleId($payload);
        $plateNo   = $payload['plateNo'] ?? data_get($payload, 'vehicle.name');
        $sensors   = [];

        foreach ($this->extractSensorMap($payload, ['temperature', 'temperaturePerType']) as $name => $value) {
            $sensors[] = $this->makeSafeeSensorPayload($payload, $vehicleId, $plateNo, 'temperature', (string) $name, $value, 'celsius');
        }

        foreach ($this->extractSensorMap($payload, ['door', 'doorPerType']) as $name => $value) {
            $sensors[] = $this->makeSafeeSensorPayload($payload, $vehicleId, $plateNo, 'door', (string) $name, $value);
        }

        foreach ($this->extractSensorMap($payload, ['humidity', 'humidityPerType']) as $name => $value) {
            $sensors[] = $this->makeSafeeSensorPayload($payload, $vehicleId, $plateNo, 'humidity', (string) $name, $value, 'percent');
        }

        return $sensors;
    }

    protected function extractSensorMap(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);

            if (is_array($value) && !empty($value)) {
                return $value;
            }
        }

        return [];
    }

    protected function makeSafeeSensorPayload(array $sourcePayload, mixed $vehicleId, mixed $plateNo, string $type, string $name, mixed $value, ?string $unit = null): array
    {
        return array_filter([
            'internal_id' => implode(':', ['safee', $vehicleId ?: 'unknown_vehicle', $type, $name]),
            'external_id' => implode(':', ['safee', $vehicleId ?: 'unknown_vehicle', $type, $name]),
            'name'        => $name,
            'type'        => $type,
            'sensor_type' => $type,
            'value'       => $value,
            'unit'        => $unit,
            'recorded_at' => $sourcePayload['date'] ?? $sourcePayload['deviceTime'] ?? null,
            'date'        => $sourcePayload['date'] ?? null,
            'deviceTime'  => $sourcePayload['deviceTime'] ?? null,
            'status'      => 'active',
            'vehicle_id'  => $vehicleId,
            'plate_no'    => $plateNo,
            'source'      => $type,
            'raw'         => [
                'name'  => $name,
                'type'  => $type,
                'value' => $value,
                'unit'  => $unit,
            ],
            'meta'        => [
                'provider'   => 'safee',
                'vehicle_id' => $vehicleId,
                'plate_no'   => $plateNo,
                'source'     => $type,
                'raw'        => [
                    'name'  => $name,
                    'type'  => $type,
                    'value' => $value,
                    'unit'  => $unit,
                ],
            ],
        ], fn ($value) => $value !== null);
    }
}
