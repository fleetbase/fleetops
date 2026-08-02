<?php

use Fleetbase\FleetOps\Support\Telematics\Providers\FlespiProvider;
use Illuminate\Support\Carbon;

class FleetOpsFlespiProviderProbe extends FlespiProvider
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

test('flespi provider authenticates and reports connection metadata', function () {
    $provider                  = new FleetOpsFlespiProviderProbe();
    $provider->queuedResponses = [
        ['result' => [['id' => 1], ['id' => 2]]],
    ];

    expect($provider->authenticateForTest(['token' => 'token-123']))->toBe([
        'Authorization' => 'FlespiToken token-123',
        'Accept'        => 'application/json',
    ]);

    $result = $provider->testConnection(['token' => 'token-456']);

    expect($result)->toBe([
        'success'  => true,
        'message'  => 'Connection successful',
        'metadata' => ['channels_count' => 2],
    ])
        ->and($provider->requests)->toBe([
            ['GET', '/channels/all', []],
        ]);

    $failing          = new FleetOpsFlespiProviderProbe();
    $failing->failure = new RuntimeException('bad token');

    expect($failing->testConnection(['token' => 'bad']))->toBe([
        'success'  => false,
        'message'  => 'bad token',
        'metadata' => [],
    ]);
});

test('flespi provider fetches devices with cursor pagination and details', function () {
    $provider                  = new FleetOpsFlespiProviderProbe();
    $provider->queuedResponses = [
        ['result' => [['id' => 1], ['id' => 2]]],
        ['result' => [['id' => 3]]],
        ['result' => [['id' => 99, 'name' => 'Device 99']]],
    ];

    expect($provider->fetchDevices(['limit' => 2]))->toBe([
        'devices'     => [['id' => 1], ['id' => 2]],
        'next_cursor' => 2,
        'has_more'    => true,
    ])
        ->and($provider->fetchDevices(['limit' => 2, 'cursor' => 2]))->toBe([
            'devices'     => [['id' => 3]],
            'next_cursor' => null,
            'has_more'    => false,
        ])
        ->and($provider->fetchDeviceDetails('99'))->toBe(['id' => 99, 'name' => 'Device 99'])
        ->and($provider->requests)->toBe([
            ['GET', '/devices/all', ['count' => 2]],
            ['GET', '/devices/all', ['count' => 2, 'offset' => 2]],
            ['GET', '/devices/99', []],
        ]);
});

test('flespi provider normalizes telemetry devices events and sensors', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'UTC'));

    try {
        $provider = new FlespiProvider();
        $device   = $provider->normalizeDevice([
            'id'              => 'device-1',
            'name'            => 'Truck One',
            'device_type_id'  => 'type-1',
            'configuration'   => ['ident' => 'imei-1', 'serial' => 'serial-1', 'phone' => '+155555501'],
            'status'          => 'enabled',
            'telemetry'       => [
                'timestamp'                => 1785067200,
                'position.latitude'        => 1.25,
                'position.longitude'       => 103.75,
                'position.speed'           => 44,
                'position.direction'       => 180,
                'position.altitude'        => 12,
                'vehicle.mileage'          => 1200,
                'engine.ignition.status'   => 'true',
                'fuel.level'               => 73,
                'online'                   => 'false',
            ],
        ]);
        $fallback = $provider->normalizeDevice([
            'device.id'          => 'device-2',
            'device.name'        => 'Fallback Name',
            'ident'              => 'imei-2',
            'last_active'        => '2026-07-26T11:30:00Z',
            'connected'          => 1,
            'position.latitude'  => 2.5,
            'position.longitude' => 104.5,
            'vehicle.speed'      => 12,
            'position.heading'   => 90,
            'vehicle.odometer'   => 450,
            'ignition.status'    => 0,
            'can.fuel.level'     => 50,
        ]);
        $event = $provider->normalizeEvent([
            'id'                 => 'event-1',
            'device.id'          => 'device-1',
            'event.enum'         => 'overspeed',
            'timestamp'          => '2026-07-26 10:00:00',
            'device.connected'   => 'yes',
            'position.latitude'  => 1.3,
            'position.longitude' => 103.8,
        ]);
        $sensor = $provider->normalizeSensor([
            'sensor_type' => 'temperature',
            'value'       => 21.5,
            'unit'        => 'c',
            'timestamp'   => 1785067200,
        ]);

        expect($device)->toMatchArray([
            'device_id'     => 'device-1',
            'external_id'   => 'device-1',
            'name'          => 'Truck One',
            'provider'      => 'flespi',
            'model'         => 'type-1',
            'imei'          => 'imei-1',
            'serial_number' => 'serial-1',
            'phone'         => '+155555501',
            'status'        => 'active',
            'online'        => false,
            'last_seen_at'  => '2026-07-26 12:00:00',
            'location'      => ['lat' => 1.25, 'lng' => 103.75],
            'speed'         => 44,
            'heading'       => 180,
            'altitude'      => 12,
            'odometer'      => 1200,
            'ignition'      => true,
            'fuel_level'    => 73,
        ])
            ->and($device['meta']['provider_status'])->toBe(['status' => 'enabled', 'online' => 'false'])
            ->and($fallback)->toMatchArray([
                'device_id'    => 'device-2',
                'name'         => 'Fallback Name',
                'status'       => 'inactive',
                'online'       => true,
                'last_seen_at' => '2026-07-26 11:30:00',
                'speed'        => 12,
                'heading'      => 90,
                'odometer'     => 450,
                'ignition'     => false,
                'fuel_level'   => 50,
            ])
            ->and($event)->toMatchArray([
                'external_id' => 'event-1',
                'device_id'   => 'device-1',
                'event_type'  => 'overspeed',
                'occurred_at' => '2026-07-26 10:00:00',
                'online'      => true,
                'location'    => ['lat' => 1.3, 'lng' => 103.8],
            ])
            ->and($sensor)->toMatchArray([
                'sensor_type' => 'temperature',
                'value'       => 21.5,
                'unit'        => 'c',
                'recorded_at' => '2026-07-26 12:00:00',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('flespi provider validates webhooks and normalizes webhook message batches', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-26 12:00:00', 'UTC'));

    try {
        $provider  = new FlespiProvider();
        $payload   = 'raw-body';
        $signature = hash_hmac('sha256', $payload, 'secret');
        $processed = $provider->processWebhook([
            [
                'id'                 => 'event-1',
                'device.id'          => 'device-1',
                'device.name'        => 'Truck One',
                'timestamp'          => 1785067200,
                'position.latitude'  => 1.25,
                'position.longitude' => 103.75,
            ],
            ['id' => 'ignored'],
        ]);

        expect($provider->validateWebhookSignature($payload, 'anything', []))->toBeTrue()
            ->and($provider->validateWebhookSignature($payload, $signature, ['webhook_secret' => 'secret']))->toBeTrue()
            ->and($provider->validateWebhookSignature($payload, 'wrong', ['webhook_secret' => 'secret']))->toBeFalse()
            ->and($processed['devices'])->toHaveCount(1)
            ->and($processed['devices'][0]['device_id'])->toBe('device-1')
            ->and($processed['events'])->toHaveCount(1)
            ->and($processed['events'][0]['external_id'])->toBe('event-1')
            ->and($processed['sensors'])->toBe([]);
    } finally {
        Carbon::setTestNow();
    }
});

test('flespi provider exposes credential schema webhook support and rate limits', function () {
    $provider = new FlespiProvider();
    $schema   = $provider->getCredentialSchema();

    expect($provider->supportsWebhooks())->toBeTrue()
        ->and($provider->supportsDiscovery())->toBeTrue()
        ->and($provider->getRateLimits())->toBe([
            'requests_per_minute' => 100,
            'burst_size'          => 10,
        ])
        ->and($schema)->toHaveCount(2)
        ->and($schema[0]['name'])->toBe('token')
        ->and($schema[0]['required'])->toBeTrue()
        ->and($schema[0]['validation'])->toBe('required|string|min:20')
        ->and($schema[1]['name'])->toBe('webhook_secret')
        ->and($schema[1]['required'])->toBeFalse();
});
