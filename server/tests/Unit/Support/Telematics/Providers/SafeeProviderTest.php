<?php

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\SafeeProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class FleetOpsSafeeProviderUnitProbe extends SafeeProvider
{
    public array $postCalls = [];
    public array $responses = [];

    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function setTelematicForTest(Telematic $telematic): void
    {
        $this->telematic = $telematic;
    }

    public function headersForTest(): array
    {
        return $this->headers;
    }

    public function baseUrlForTest(): string
    {
        return $this->baseUrl;
    }

    public function authContextForTest(): array
    {
        return $this->authContext;
    }

    public function prepareAuthenticationForTest(): void
    {
        $this->prepareAuthentication();
    }

    public function queuePostResponse(array|Throwable $response): void
    {
        $this->responses[] = $response;
    }

    protected function safeePost(string $endpoint, array|stdClass $payload = [], bool $dataEndpoint = false): array
    {
        $this->postCalls[] = [$endpoint, $payload, $dataEndpoint];

        $response = array_shift($this->responses);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response ?? [];
    }
}

function fleetopsSafeeProvider(array $credentials = []): FleetOpsSafeeProviderUnitProbe
{
    $provider = new FleetOpsSafeeProviderUnitProbe();
    $provider->setCredentialsForTest(array_merge([
        'server_uri'   => 'https://safee.example.test/',
        'access_token' => 'static-token',
    ], $credentials));

    return $provider;
}

test('safee provider prepares static token authentication and reports connection diagnostics', function () {
    $provider = fleetopsSafeeProvider([
        'language'             => 'ar',
        'authorization_scheme' => 'Token',
    ]);
    $provider->prepareAuthenticationForTest();

    expect($provider->baseUrlForTest())->toBe('https://safee.example.test')
        ->and($provider->headersForTest())->toBe([
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
            'Accept-Language' => 'ar',
            'Authorization'   => 'Token static-token',
        ]);

    Http::swap(new HttpFactory());
    Http::fake([
        'https://safee.example.test/api/v2/status' => Http::response([
            'code'    => 0,
            'message' => 'ready',
            'status'  => 'ok',
            'time'    => 1785168000,
        ]),
    ]);

    expect($provider->testConnection([
        'server_uri'   => 'https://safee.example.test/',
        'access_token' => 'static-token',
    ]))->toBe([
        'success'  => true,
        'message'  => 'ready',
        'metadata' => [
            'status' => 'ok',
            'time'   => 1785168000,
        ],
    ]);
});

test('safee provider authenticates through oidc and masks connection failures', function () {
    Http::swap(new HttpFactory());
    Http::fake([
        'https://safee.example.test/auth/realms/fleetbase/protocol/openid-connect/token' => Http::response([
            'access_token' => 'oidc-token',
        ]),
        'https://safee.example.test/api/v2/status' => Http::response(['code' => 1, 'message' => 'not ready']),
    ]);

    $provider = fleetopsSafeeProvider([
        'access_token'  => null,
        'realm_id'      => 'fleetbase',
        'client_id'     => 'client-id',
        'client_secret' => 'client-secret',
        'username'      => 'user',
        'password'      => 'secret',
    ]);

    expect($provider->testConnection([
        'server_uri'    => 'https://safee.example.test/',
        'realm_id'      => 'fleetbase',
        'client_id'     => 'client-id',
        'client_secret' => 'client-secret',
        'username'      => 'user',
        'password'      => 'secret',
    ]))->toBe([
        'success'  => false,
        'message'  => 'not ready',
        'metadata' => [
            'status'    => null,
            'time'      => null,
            'auth_host' => 'https://safee.example.test',
            'auth_path' => '/auth/realms/fleetbase/protocol/openid-connect/token',
            'realm_id'  => 'fleetbase',
        ],
    ]);

    Http::swap(new HttpFactory());
    Http::fake([
        'https://safee.example.test/auth/realms/fleetbase/protocol/openid-connect/token' => Http::response([], 503),
    ]);

    $failure = fleetopsSafeeProvider([
        'access_token'  => null,
        'realm_id'      => 'fleetbase',
        'client_id'     => 'client-id',
        'client_secret' => 'client-secret',
        'username'      => 'user',
        'password'      => 'secret',
    ]);

    expect($failure->testConnection([
        'server_uri'    => 'https://safee.example.test/',
        'realm_id'      => 'fleetbase',
        'client_id'     => 'client-id',
        'client_secret' => 'client-secret',
        'username'      => 'user',
        'password'      => 'secret',
    ]))->toBe([
        'success'  => false,
        'message'  => 'Safee authentication failed with status 503',
        'metadata' => [
            'auth_host' => 'https://safee.example.test',
            'auth_path' => '/auth/realms/fleetbase/protocol/openid-connect/token',
            'realm_id'  => 'fleetbase',
        ],
    ]);
});

test('safee provider fetches devices with identity diagnostics and last state fallbacks', function () {
    $provider = fleetopsSafeeProvider();
    $provider->queuePostResponse([
        'result' => [
            ['id' => 101, 'plateNo' => 'TRK-101'],
            ['id'     => 101, 'plateNo' => 'TRK-101-DUP'],
            ['uuid'   => 'missing-id'],
            ['_safee' => ['vehicle_id' => 202], 'plateNo' => 'TRK-202'],
        ],
    ]);
    $provider->queuePostResponse([
        'result' => [
            ['vehicleId' => 101, 'status' => 'active', 'speed' => 44],
            ['vehicle' => ['id' => 202], 'status' => 'offline'],
            'ignored-state',
        ],
    ]);

    $result = $provider->fetchDevices([
        'filter'     => (object) ['plateNo' => 'TRK'],
        'page_size'  => 50,
        'page_index' => 2,
    ]);

    expect($provider->postCalls[0])->toBe([
        '/api/v2/vehicle/list-info',
        ['plateNo' => 'TRK', 'pageSize' => 50, 'pageIndex' => 2],
        true,
    ])
        ->and($provider->postCalls[1])->toBe([
            '/api/v2/vehicle/last-state',
            [
                'live'      => true,
                'startDate' => null,
                'endDate'   => null,
                'vehicles'  => [101, 202],
            ],
            true,
        ])
        ->and($result['devices'])->toHaveCount(4)
        ->and($result['devices'][0]['_safee']['current_state']['speed'])->toBe(44)
        ->and($result['devices'][3]['_safee']['current_state']['status'])->toBe('offline')
        ->and($result['sync_meta']['safee_last_endpoint_counts'])->toMatchArray([
            'vehicles_listed'                 => 4,
            'unique_vehicle_ids'              => 2,
            'missing_vehicle_ids'             => 1,
            'duplicate_vehicle_ids'           => ['101' => 2],
            'list_info_page_size'             => 50,
            'list_info_requested_unpaginated' => false,
            'last_state_fetched'              => 2,
        ]);
});

test('safee provider enriches telemetry snapshots and records endpoint failures', function () {
    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid' => 'telematic-safee',
        'meta' => [
            'safee_last_telemetry_synced_at' => 2000,
        ],
    ], true);

    $provider = fleetopsSafeeProvider();
    $provider->setTelematicForTest($telematic);
    $provider->queuePostResponse([
        'result' => [
            'vehicleId'           => 101,
            'plateNo'             => 'TRK-101',
            'date'                => '2026-07-27T11:00:00Z',
            'temperaturePerType'  => ['cargo' => 4],
            'doorPerType'         => ['rear' => 'closed'],
            'humidityPerType'     => ['cargo' => 60],
            'vehicleFuel'         => ['level' => 72],
        ],
    ]);
    $provider->queuePostResponse([
        'result' => [
            ['id' => 'pos-1', 'vehicleId' => 101, 'latitude' => 1.2, 'longitude' => 3.4],
            ['id' => 'pos-2', 'vehicleId' => 101, 'latitude' => 5.6, 'longitude' => 7.8],
        ],
    ]);
    $provider->queuePostResponse(new RuntimeException('password=secret token=abc123 failed'));

    $result = $provider->fetchDeviceTelemetrySnapshots([
        ['id' => 101, 'plateNo' => 'TRK-101', '_safee' => ['current_state' => ['vehicleId' => 101, 'status' => 'active']]],
        ['plateNo' => 'MISSING-ID'],
    ], [
        'end_date' => 2500,
    ]);

    expect($provider->postCalls)->toHaveCount(3)
        ->and($provider->postCalls[0][0])->toBe('/api/v2/vehicle/last-info')
        ->and($provider->postCalls[1][0])->toBe('/api/v2/vehicle/positions')
        ->and($provider->postCalls[1][1])->toMatchArray([
            'vehicleId' => 101,
            'startDate' => 1880,
            'endDate'   => 2500,
        ])
        ->and($provider->postCalls[2][0])->toBe('/api/v2/vehicle/events')
        ->and($result['devices'][0]['_safee']['current_info']['plateNo'])->toBe('TRK-101')
        ->and($result['devices'][0]['_safee']['positions'])->toHaveCount(2)
        ->and($result['devices'][0]['_safee']['events'])->toBe([])
        ->and($result['devices'][0]['sensors'])->toHaveCount(3)
        ->and($result['devices'][1]['_safee']['vehicle_id'])->toBeNull()
        ->and($result['sync_meta']['safee_last_sync_window'])->toBe([
            'startDate' => 1880.0,
            'endDate'   => 2500.0,
        ])
        ->and($result['sync_meta']['safee_last_endpoint_counts'])->toMatchArray([
            'vehicles_listed'                => 2,
            'unique_vehicle_ids'             => 1,
            'missing_vehicle_ids'            => 1,
            'last_info_fetched'              => 1,
            'positions_fetched'              => 2,
            'events_fetched'                 => 0,
            'devices_returned_for_ingestion' => 2,
        ])
        ->and($result['sync_meta']['safee_last_endpoint_counts']['failures'][0])->toBe([
            'endpoint'   => '/api/v2/vehicle/events',
            'vehicle_id' => 101,
            'message'    => 'password=[redacted] token=[redacted] failed',
        ]);
});

test('safee provider normalizes device events sensors and schema contracts', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));

    try {
        $provider = new SafeeProvider();
        $payload  = [
            '_safee' => [
                'vehicle_id'    => 101,
                'identity'      => [
                    'id'      => 101,
                    'uuid'    => 'vehicle-uuid',
                    'plateNo' => 'TRK-101',
                    'driver'  => ['name' => 'Driver One'],
                    'device'  => ['imei' => 'imei-1', 'serial' => 'serial-1'],
                ],
                'current_state' => [
                    'vehicleId'   => 101,
                    'status'      => 'offline',
                    'date'        => '2026-07-27T10:00:00Z',
                    'position'    => ['lat' => 1.23, 'lon' => 4.56],
                    'event'       => ['id' => 'event-current', 'code' => 'ignition_on', 'name' => 'Ignition on'],
                    'canbus'      => ['odometer' => 12345],
                    'fuel'        => ['level' => 88],
                    'speed'       => 35,
                    'temperature' => 5,
                ],
                'current_info'  => [
                    'vehicleId'       => 101,
                    'status'          => 'active',
                    'deviceTime'      => '2026-07-27T10:05:00Z',
                    'vehicleCanbus'   => ['odometer' => 12500],
                    'vehicleFuel'     => ['level' => 90],
                    'humidityPerType' => ['cargo' => 55],
                    'door'            => ['rear' => 'open'],
                ],
                'positions'     => [
                    ['id' => 'pos-1', 'vehicleId' => 101, 'date' => '2026-07-27T10:10:00Z', 'loc' => ['coordinates' => [4.5, 1.5]]],
                    'ignored-position',
                ],
                'events'        => [
                    ['id' => 'evt-2', 'vehicle' => ['id' => 101], 'type' => ['key' => 'door_open', 'value' => 'Door opened'], 'date' => '2026-07-27T10:15:00Z'],
                ],
                'sync_window'   => ['startDate' => 1, 'endDate' => 2],
                'diagnostics'   => ['last_info_fetched' => 1],
            ],
        ];

        $device = $provider->normalizeDevice($payload);
        $events = $provider->normalizeEvents($payload);
        $sensor = $provider->normalizeSensor([
            'sensor_id'   => 'sensor-1',
            'name'        => 'Cargo temp',
            'sensor_type' => 'temperature',
            'lastValue'   => 4,
            'recorded_at' => 1785168000000,
            'meta'        => ['zone' => 'cargo'],
            'vehicle_id'  => 101,
            'plate_no'    => 'TRK-101',
        ]);
        $schema = $provider->getCredentialSchema();

        expect($device)->toMatchArray([
            'device_id'     => 101,
            'external_id'   => 101,
            'name'          => 'TRK-101',
            'provider'      => 'safee',
            'internal_id'   => 'vehicle-uuid',
            'imei'          => 'imei-1',
            'serial_number' => 'serial-1',
            'status'        => 'active',
            'online'        => true,
            'last_seen_at'  => '2026-07-27 10:00:00',
            'location'      => ['lat' => 1.23, 'lng' => 4.56],
            'speed'         => 35,
            'odometer'      => 12345,
            'fuel_level'    => 88,
        ])
            ->and($device['meta']['capabilities'])->toBe([
                'tracking'       => true,
                'odometer'       => true,
                'fuel_level'     => true,
                'ignition_state' => true,
            ])
            ->and($events)->toHaveCount(3)
            ->and($events[0])->toMatchArray([
                'external_id'       => 'safee:current:101:event-current:2026-07-27T10:00:00Z',
                'event_type'        => 'ignition_on',
                'message'           => 'Ignition on',
                'online'            => true,
                'location'          => ['lat' => 1.23, 'lng' => 4.56],
                'odometer'          => 12345,
                'fuel_level'        => 88,
            ])
            ->and($events[1]['event_type'])->toBe('position_update')
            ->and($events[1]['location'])->toBe(['lat' => 1.5, 'lng' => 4.5])
            ->and($events[2]['event_type'])->toBe('door_open')
            ->and($events[2]['message'])->toBe('Door opened')
            ->and($sensor)->toMatchArray([
                'internal_id' => 'sensor-1',
                'external_id' => null,
                'name'        => 'Cargo temp',
                'type'        => 'temperature',
                'value'       => 4,
                'recorded_at' => '2026-07-27 16:00:00',
                'status'      => 'active',
                'meta'        => [
                    'zone'       => 'cargo',
                    'provider'   => 'safee',
                    'vehicle_id' => 101,
                    'plate_no'   => 'TRK-101',
                    'raw'        => [
                        'sensor_id'   => 'sensor-1',
                        'name'        => 'Cargo temp',
                        'sensor_type' => 'temperature',
                        'lastValue'   => 4,
                        'recorded_at' => 1785168000000,
                        'meta'        => ['zone' => 'cargo'],
                        'vehicle_id'  => 101,
                        'plate_no'    => 'TRK-101',
                    ],
                ],
            ])
            ->and(array_column($schema, 'name'))->toBe([
                'server_uri',
                'realm_id',
                'client_id',
                'client_secret',
                'username',
                'password',
            ])
            ->and($schema[0]['validation'])->toBe('nullable|url')
            ->and($schema[5]['required'])->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('safee transport helpers fetch details guard credentials and surface failures', function () {
    $provider = fleetopsSafeeProvider();
    $invoke   = function (string $name, ...$arguments) use ($provider) {
        $reflection = new ReflectionMethod(SafeeProvider::class, $name);
        $reflection->setAccessible(true);

        return $reflection->invoke($provider, ...$arguments);
    };

    // Missing oidc credentials raise per-field requirements
    $bare = fleetopsSafeeProvider(['access_token' => null]);
    expect(function () use ($bare) {
        $reflection = new ReflectionMethod(SafeeProvider::class, 'authenticate');
        $reflection->setAccessible(true);
        $reflection->invoke($bare);
    })->toThrow(InvalidArgumentException::class);

    // Empty vehicle id lists skip the state lookup entirely
    expect($invoke('fetchLastStatesByVehicle', []))->toBe([]);

    // Device details post through the real safee transport with numeric coercion
    $raw = new class extends SafeeProvider {
        public function setCredentialsForTest(array $credentials): void
        {
            $this->credentials = $credentials;
        }
    };
    $raw->setCredentialsForTest(['server_uri' => 'https://safee.example.test/', 'access_token' => 'static-token']);
    $rawInvoke = function (string $name, ...$arguments) use ($raw) {
        $reflection = new ReflectionMethod(SafeeProvider::class, $name);
        $reflection->setAccessible(true);

        return $reflection->invoke($raw, ...$arguments);
    };
    Http::clearResolvedInstances();
    app()->forgetInstance(HttpFactory::class);
    Http::fake(['*' => Http::response(['result' => ['id' => 42, 'name' => 'Vehicle 42']], 200)]);
    expect($raw->fetchDeviceDetails('42'))->toBe(['id' => 42, 'name' => 'Vehicle 42']);

    // Failed responses surface as runtime exceptions from both verbs
    Http::clearResolvedInstances();
    app()->forgetInstance(HttpFactory::class);
    Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);
    expect(fn () => $rawInvoke('safeeGet', '/api/v2/broken'))->toThrow(RuntimeException::class)
        ->and(fn () => $rawInvoke('safeePost', '/api/v2/broken', ['x' => 1]))->toThrow(RuntimeException::class);
});
