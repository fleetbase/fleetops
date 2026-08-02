<?php

use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\GeotabProvider;
use Illuminate\Support\Carbon;

class FleetOpsGeotabProviderProbe extends GeotabProvider
{
    public array $calls     = [];
    public array $responses = [];

    public function queueResponse(array $response): void
    {
        $this->responses[] = $response;
    }

    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function sessionIdForTest(): ?string
    {
        return $this->sessionId;
    }

    public function headersForTest(): array
    {
        return $this->headers;
    }

    public function prepareAuthenticationForTest(): void
    {
        $this->prepareAuthentication();
    }

    public function fetchLatestLogRecordsForTest(array $devices, array $options = []): array
    {
        return $this->fetchLatestLogRecords($devices, $options);
    }

    public function parseTimestampForTest($value): ?string
    {
        return $this->parseTimestamp($value);
    }

    protected function postGeotab(array $payload): ?array
    {
        $this->calls[] = $payload;

        return array_shift($this->responses);
    }
}

function fleetopsGeotabProvider(array $credentials = []): FleetOpsGeotabProviderProbe
{
    $provider = new FleetOpsGeotabProviderProbe();
    $provider->setCredentialsForTest(array_merge([
        'database' => 'fleetbase-db',
        'username' => 'fleetbase-user',
        'password' => 'fleetbase-secret',
    ], $credentials));

    return $provider;
}

test('geotab provider authenticates and masks session metadata', function () {
    $provider = fleetopsGeotabProvider();
    $provider->queueResponse([
        'result' => [
            'credentials' => [
                'sessionId' => '0123456789abcdef',
            ],
        ],
    ]);

    expect($provider->testConnection([
        'database' => 'fleetbase-db',
        'username' => 'fleetbase-user',
        'password' => 'fleetbase-secret',
    ]))->toBe([
        'success'  => true,
        'message'  => 'Connection successful',
        'metadata' => [
            'session_id' => '0123456789...',
        ],
    ])
        ->and($provider->sessionIdForTest())->toBe('0123456789abcdef')
        ->and($provider->calls)->toBe([
            [
                'method' => 'Authenticate',
                'params' => [
                    'database' => 'fleetbase-db',
                    'userName' => 'fleetbase-user',
                    'password' => 'fleetbase-secret',
                ],
            ],
        ]);
});

test('geotab provider reports authentication failures and prepares headers after connect', function () {
    $failure = fleetopsGeotabProvider();
    $failure->queueResponse(['result' => []]);

    expect($failure->testConnection([
        'database' => 'fleetbase-db',
        'username' => 'fleetbase-user',
        'password' => 'fleetbase-secret',
    ]))->toBe([
        'success'  => false,
        'message'  => 'Geotab authentication failed',
        'metadata' => [],
    ]);

    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'        => 'telematic-geotab',
        'credentials' => [
            'database' => 'connected-db',
            'username' => 'connected-user',
            'password' => 'connected-secret',
        ],
    ], true);

    $connected = new FleetOpsGeotabProviderProbe();
    $connected->queueResponse([
        'result' => [
            'credentials' => [
                'sessionId' => 'connected-session',
            ],
        ],
    ]);

    $connected->connect($telematic);

    expect($connected->sessionIdForTest())->toBe('connected-session')
        ->and($connected->headersForTest())->toBe(['Content-Type' => 'application/json'])
        ->and($connected->calls[0]['params'])->toMatchArray([
            'database' => 'connected-db',
            'userName' => 'connected-user',
            'password' => 'connected-secret',
        ]);

    $connected->prepareAuthenticationForTest();

    expect($connected->calls)->toHaveCount(1);
});

test('geotab provider fetches devices details and latest log records through api calls', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-27 12:00:00'));

    try {
        $provider = fleetopsGeotabProvider();
        $provider->queueResponse([
            'result' => [
                ['id' => 'device-1', 'name' => 'Truck 1'],
                ['id'   => 'device-2', 'name' => 'Truck 2'],
                ['name' => 'Unnamed missing id'],
            ],
        ]);
        $provider->queueResponse([
            'result' => [
                ['id' => 'old-log', 'device' => ['id' => 'device-1'], 'dateTime' => '2026-07-27T08:00:00Z', 'latitude' => 1, 'longitude' => 2],
                ['id' => 'new-log', 'device' => ['id' => 'device-1'], 'dateTime' => '2026-07-27T09:00:00Z', 'latitude' => 3, 'longitude' => 4],
                ['id' => 'other-log', 'device' => ['id' => 'other-device'], 'dateTime' => '2026-07-27T10:00:00Z'],
                ['id' => 'missing-device', 'dateTime' => '2026-07-27T10:00:00Z'],
            ],
        ]);
        $provider->queueResponse([
            'result' => [
                ['id' => 'device-1', 'name' => 'Truck 1', 'serialNumber' => 'serial-1'],
            ],
        ]);

        $devices = $provider->fetchDevices(['limit' => 3, 'from_date' => '2026-07-27T00:00:00Z']);
        $details = $provider->fetchDeviceDetails('device-1');

        expect($devices)->toMatchArray([
            'next_cursor' => null,
            'has_more'    => false,
        ])
            ->and($devices['devices'])->toHaveCount(3)
            ->and($devices['devices'][0]['latest_log_record'])->toMatchArray([
                'id'        => 'new-log',
                'latitude'  => 3,
                'longitude' => 4,
            ])
            ->and(array_key_exists('latest_log_record', $devices['devices'][1]))->toBeFalse()
            ->and($details)->toBe([
                'id'           => 'device-1',
                'name'         => 'Truck 1',
                'serialNumber' => 'serial-1',
            ])
            ->and($provider->calls)->toMatchArray([
                [
                    'method' => 'Get',
                    'params' => [
                        'typeName'     => 'Device',
                        'resultsLimit' => 3,
                        'credentials'  => [
                            'database'  => 'fleetbase-db',
                            'sessionId' => null,
                        ],
                    ],
                ],
                [
                    'method' => 'Get',
                    'params' => [
                        'typeName'     => 'LogRecord',
                        'search'       => ['fromDate' => '2026-07-27T00:00:00Z'],
                        'resultsLimit' => 100,
                        'credentials'  => [
                            'database'  => 'fleetbase-db',
                            'sessionId' => null,
                        ],
                    ],
                ],
                [
                    'method' => 'Get',
                    'params' => [
                        'typeName'    => 'Device',
                        'search'      => ['id' => 'device-1'],
                        'credentials' => [
                            'database'  => 'fleetbase-db',
                            'sessionId' => null,
                        ],
                    ],
                ],
            ]);

        $emptyLogs = $provider->fetchLatestLogRecordsForTest([
            ['name' => 'No external id'],
        ]);

        expect($emptyLogs)->toBe([]);
    } finally {
        Carbon::setTestNow();
    }
});

test('geotab provider normalizes fallback device event sensor and schema contracts', function () {
    $provider = new GeotabProvider();

    $device = $provider->normalizeDevice([
        'id'         => 'device-3',
        'activeFrom' => '2026-01-01',
        'activeTo'   => '2026-12-31',
        'groups'     => [['id' => 'group-1']],
    ]);
    $event = $provider->normalizeEvent([
        'id'        => 'event-1',
        'deviceId'  => 'device-3',
        'type'      => 'fault',
        'dateTime'  => '2026-07-27T11:30:00Z',
        'latitude'  => 12.34,
        'longitude' => 56.78,
        'heading'   => 270,
    ]);
    $sensor = $provider->normalizeSensor([
        'diagnosticType' => 'engine_temperature',
        'data'           => 92,
        'dateTime'       => '2026-07-27T11:31:00Z',
    ]);

    expect($device)->toMatchArray([
        'device_id'    => 'device-3',
        'name'         => 'Unknown Device',
        'online'       => null,
        'last_seen_at' => null,
        'location'     => ['lat' => null, 'lng' => null],
    ])
        ->and($device['meta']['provider_status'])->toBe([
            'active_from' => '2026-01-01',
            'active_to'   => '2026-12-31',
            'groups'      => [['id' => 'group-1']],
            'has_log'     => false,
        ])
        ->and($event)->toMatchArray([
            'external_id' => 'event-1',
            'device_id'   => 'device-3',
            'event_type'  => 'fault',
            'occurred_at' => '2026-07-27 11:30:00',
            'online'      => true,
            'location'    => ['lat' => 12.34, 'lng' => 56.78],
            'heading'     => 270,
        ])
        ->and($sensor)->toBe([
            'sensor_type' => 'engine_temperature',
            'value'       => 92,
            'recorded_at' => '2026-07-27T11:31:00Z',
            'meta'        => [
                'diagnosticType' => 'engine_temperature',
                'data'           => 92,
                'dateTime'       => '2026-07-27T11:31:00Z',
            ],
        ])
        ->and($provider->getCredentialSchema())->toBe([
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
        ])
        ->and($provider->supportsWebhooks())->toBeFalse()
        ->and($provider->getRateLimits()['requests_per_minute'])->toBe(50);
});

test('geotab provider parses nullable timestamps', function () {
    $provider = fleetopsGeotabProvider();

    expect($provider->parseTimestampForTest(null))->toBeNull()
        ->and($provider->parseTimestampForTest('2026-07-27T11:45:00Z'))->toBe('2026-07-27 11:45:00');
});
