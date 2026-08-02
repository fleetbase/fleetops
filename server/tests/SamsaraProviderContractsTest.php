<?php

use Fleetbase\FleetOps\Support\Telematics\Providers\SamsaraProvider;
use Illuminate\Support\Carbon;

class FleetOpsSamsaraProviderProbe extends SamsaraProvider
{
    public array $requests        = [];
    public array $queuedResponses = [];
    public ?Throwable $failure    = null;

    public function authenticateForTest(array $credentials): array
    {
        $this->credentials = $credentials;
        $this->prepareAuthentication();

        return $this->headers;
    }

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        $this->requests[] = [$method, $endpoint, $data];

        if ($this->failure) {
            throw $this->failure;
        }

        return array_shift($this->queuedResponses) ?? [];
    }
}

test('samsara provider authenticates and reports connection metadata', function () {
    $provider                  = new FleetOpsSamsaraProviderProbe();
    $provider->queuedResponses = [
        ['data' => [['id' => 'user-1'], ['id' => 'user-2']]],
    ];

    expect($provider->authenticateForTest(['api_token' => 'token-123']))->toBe([
        'Authorization' => 'Bearer token-123',
        'Accept'        => 'application/json',
    ]);

    $result = $provider->testConnection(['api_token' => 'token-456']);

    expect($result)->toBe([
        'success'  => true,
        'message'  => 'Connection successful',
        'metadata' => ['users_count' => 2],
    ])
        ->and($provider->requests)->toBe([
            ['GET', '/fleet/users', []],
        ]);

    $failing          = new FleetOpsSamsaraProviderProbe();
    $failing->failure = new RuntimeException('forbidden');

    expect($failing->testConnection(['api_token' => 'bad']))->toBe([
        'success'  => false,
        'message'  => 'forbidden',
        'metadata' => [],
    ]);
});

test('samsara provider fetches devices with cursor pagination and details', function () {
    $provider                  = new FleetOpsSamsaraProviderProbe();
    $provider->queuedResponses = [
        [
            'data'       => [['id' => 'veh-1'], ['id' => 'veh-2']],
            'pagination' => ['hasNextPage' => true, 'endCursor' => 'cursor-2'],
        ],
        [
            'data'       => [['id' => 'veh-3']],
            'pagination' => ['hasNextPage' => false, 'endCursor' => 'cursor-3'],
        ],
        ['data' => ['id' => 'veh-99', 'name' => 'Vehicle 99']],
    ];

    expect($provider->fetchDevices(['limit' => 2]))->toBe([
        'devices'     => [['id' => 'veh-1'], ['id' => 'veh-2']],
        'next_cursor' => 'cursor-2',
        'has_more'    => true,
    ])
        ->and($provider->fetchDevices(['limit' => 2, 'cursor' => 'cursor-2']))->toBe([
            'devices'     => [['id' => 'veh-3']],
            'next_cursor' => null,
            'has_more'    => false,
        ])
        ->and($provider->fetchDeviceDetails('veh-99'))->toBe(['id' => 'veh-99', 'name' => 'Vehicle 99'])
        ->and($provider->requests)->toBe([
            ['GET', '/fleet/vehicles', ['limit' => 2]],
            ['GET', '/fleet/vehicles', ['limit' => 2, 'after' => 'cursor-2']],
            ['GET', '/fleet/vehicles/veh-99', []],
        ]);
});

test('samsara provider normalizes devices events and sensors from payload variants', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'UTC'));

    try {
        $provider = new SamsaraProvider();
        $device   = $provider->normalizeDevice([
            'id'            => 'veh-1',
            'name'          => 'Truck One',
            'make'          => 'Volvo',
            'vin'           => 'VIN-1',
            'serialNumber'  => 'SERIAL-1',
            'licensePlate'  => 'ABC-123',
            'status'        => 'offline',
            'isOnline'      => 'false',
            'location'      => [
                'time'              => '2026-07-26T10:00:00Z',
                'latitude'          => 1.25,
                'longitude'         => 103.75,
                'speedMilesPerHour' => 45,
                'headingDegrees'    => 180,
                'altitudeMeters'    => 12,
            ],
            'odometerMeters' => 1200,
            'fuelPercent'    => ['value' => 73],
        ]);
        $fallback = $provider->normalizeDevice([
            'id'                => 'veh-2',
            'gps'               => [
                'time'              => '2026-07-26T11:30:00Z',
                'lat'               => 2.5,
                'lng'               => 104.5,
                'speedMilesPerHour' => 12,
                'headingDegrees'    => 90,
                'altitudeMeters'    => 5,
            ],
            'gateway'           => ['status' => 'connected', 'online' => true],
            'obdOdometerMeters' => ['value' => 450],
            'fuelPercent'       => 50,
        ]);
        $event = $provider->normalizeEvent([
            'id'              => 'event-1',
            'vehicle'         => ['id' => 'veh-1'],
            'eventType'       => 'harsh_brake',
            'time'            => '2026-07-26T09:00:00Z',
            'currentLocation' => [
                'latitude'  => 1.3,
                'longitude' => 103.8,
            ],
            'speed'          => 20,
            'headingDegrees' => 45,
            'altitudeMeters' => 7,
            'online'         => 'yes',
        ]);
        $sensor = $provider->normalizeSensor([
            'sensorType' => 'temperature',
            'value'      => 21.5,
            'unit'       => 'c',
            'time'       => '2026-07-26T08:00:00Z',
        ]);

        expect($device)->toMatchArray([
            'device_id'     => 'veh-1',
            'external_id'   => 'veh-1',
            'name'          => 'Truck One',
            'provider'      => 'samsara',
            'model'         => 'Volvo',
            'vin'           => 'VIN-1',
            'serial_number' => 'SERIAL-1',
            'license_plate' => 'ABC-123',
            'status'        => 'inactive',
            'online'        => false,
            'last_seen_at'  => '2026-07-26 10:00:00',
            'location'      => ['lat' => 1.25, 'lng' => 103.75],
            'speed'         => 45,
            'heading'       => 180,
            'altitude'      => 12,
            'odometer'      => 1200,
            'fuel_level'    => 73,
        ])
            ->and($device['meta']['provider_status'])->toBe([
                'status' => 'offline',
                'online' => 'false',
            ])
            ->and($fallback)->toMatchArray([
                'device_id'    => 'veh-2',
                'name'         => 'Unknown Device',
                'status'       => 'active',
                'online'       => true,
                'last_seen_at' => '2026-07-26 11:30:00',
                'location'     => ['lat' => 2.5, 'lng' => 104.5],
                'speed'        => 12,
                'heading'      => 90,
                'altitude'     => 5,
                'odometer'     => 450,
                'fuel_level'   => 50,
            ])
            ->and($event)->toMatchArray([
                'external_id' => 'event-1',
                'device_id'   => 'veh-1',
                'event_type'  => 'harsh_brake',
                'occurred_at' => '2026-07-26 09:00:00',
                'online'      => true,
                'location'    => ['lat' => 1.3, 'lng' => 103.8],
                'speed'       => 20,
                'heading'     => 45,
                'altitude'    => 7,
            ])
            ->and($sensor)->toMatchArray([
                'sensor_type' => 'temperature',
                'value'       => 21.5,
                'unit'        => 'c',
                'recorded_at' => '2026-07-26T08:00:00Z',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('samsara provider validates webhooks and normalizes webhook batches', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'UTC'));

    try {
        $provider  = new SamsaraProvider();
        $payload   = 'raw-body';
        $signature = hash_hmac('sha256', $payload, 'secret');
        $processed = $provider->processWebhook([
            'data' => [
                [
                    'id'      => 'event-1',
                    'time'    => '2026-07-26T10:00:00Z',
                    'vehicle' => [
                        'id'       => 'veh-1',
                        'name'     => 'Truck One',
                        'location' => [
                            'latitude'  => 1.25,
                            'longitude' => 103.75,
                        ],
                    ],
                ],
                [
                    'id'        => 'event-2',
                    'vehicleId' => 'veh-2',
                ],
            ],
        ]);

        expect($provider->validateWebhookSignature($payload, 'anything', []))->toBeTrue()
            ->and($provider->validateWebhookSignature($payload, $signature, ['webhook_secret' => 'secret']))->toBeTrue()
            ->and($provider->validateWebhookSignature($payload, 'wrong', ['webhook_secret' => 'secret']))->toBeFalse()
            ->and($processed['devices'])->toHaveCount(1)
            ->and($processed['devices'][0]['device_id'])->toBe('veh-1')
            ->and($processed['events'])->toHaveCount(2)
            ->and($processed['events'][0]['device_id'])->toBe('veh-1')
            ->and($processed['events'][1]['device_id'])->toBe('veh-2')
            ->and($processed['sensors'])->toBe([]);
    } finally {
        Carbon::setTestNow();
    }
});

test('samsara provider exposes credential schema webhook support and rate limits', function () {
    $provider = new SamsaraProvider();
    $schema   = $provider->getCredentialSchema();

    expect($provider->supportsWebhooks())->toBeTrue()
        ->and($provider->supportsDiscovery())->toBeTrue()
        ->and($provider->getRateLimits())->toBe([
            'requests_per_minute' => 60,
            'burst_size'          => 10,
        ])
        ->and($schema)->toHaveCount(2)
        ->and($schema[0]['name'])->toBe('api_token')
        ->and($schema[0]['required'])->toBeTrue()
        ->and($schema[0]['validation'])->toBe('required|string|min:20')
        ->and($schema[1]['name'])->toBe('webhook_secret')
        ->and($schema[1]['required'])->toBeFalse();
});
