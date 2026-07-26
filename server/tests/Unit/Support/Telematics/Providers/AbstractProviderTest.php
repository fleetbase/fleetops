<?php

use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AbstractProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FleetOpsAbstractTelematicProviderProbe extends AbstractProvider
{
    public function __construct()
    {
        $this->baseUrl = 'https://provider.test';
        $this->headers = ['Authorization' => 'Bearer test-token'];
    }

    public function requestForTest(string $method, string $endpoint, array $data = []): array
    {
        return $this->request($method, $endpoint, $data);
    }

    public function recordRequestForTest(): void
    {
        $this->recordRequest();
    }

    public function rateLimitKeyForTest(): string
    {
        return $this->rateLimitKey();
    }

    public function testConnection(array $credentials): array
    {
        return ['success' => true, 'message' => 'ok', 'metadata' => $credentials];
    }

    public function fetchDevices(array $options = []): array
    {
        return ['devices' => [], 'next_cursor' => null, 'has_more' => false];
    }

    public function fetchDeviceDetails(string $externalId): array
    {
        return ['external_id' => $externalId];
    }

    public function normalizeDevice(array $payload): array
    {
        return $payload;
    }

    public function normalizeEvent(array $payload): array
    {
        return $payload;
    }

    public function normalizeSensor(array $payload): array
    {
        return $payload;
    }

    protected function prepareAuthentication(): void
    {
    }
}

function fleetopsAbstractProviderUseArrayCache(): void
{
    Cache::swap(new Repository(new ArrayStore()));
}

function fleetopsAbstractProviderTelematic(): Telematic
{
    $telematic = new Telematic();
    $telematic->setRawAttributes([
        'uuid'        => 'telematic-provider',
        'credentials' => ['token' => 'array-token'],
    ], true);

    return $telematic;
}

test('abstract provider makes authenticated requests and refills rate tokens', function () {
    fleetopsAbstractProviderUseArrayCache();

    $requests = [];
    Http::fake(function ($request) use (&$requests) {
        $requests[] = [
            'method'        => $request->method(),
            'url'           => (string) $request->url(),
            'authorization' => $request->header('Authorization'),
            'data'          => $request->data(),
        ];

        return Http::response(['ok' => true, 'devices' => [['id' => 'device-1']]], 200);
    });

    $provider = new FleetOpsAbstractTelematicProviderProbe();
    $provider->connect(fleetopsAbstractProviderTelematic());

    $result = $provider->requestForTest('POST', '/devices', ['limit' => 10]);

    expect($result)->toBe(['ok' => true, 'devices' => [['id' => 'device-1']]])
        ->and($requests)->toBe([
            [
                'method'        => 'POST',
                'url'           => 'https://provider.test/devices',
                'authorization' => ['Bearer test-token'],
                'data'          => ['limit' => 10],
            ],
        ])
        ->and(Cache::get($provider->rateLimitKeyForTest()))->toBe(10);
});

test('abstract provider rejects exhausted rate limit buckets and failed responses', function () {
    fleetopsAbstractProviderUseArrayCache();

    $provider = new FleetOpsAbstractTelematicProviderProbe();
    $provider->connect(fleetopsAbstractProviderTelematic());
    Cache::put($provider->rateLimitKeyForTest(), 0, 60);

    expect(fn () => $provider->requestForTest('GET', '/blocked'))
        ->toThrow(TelematicRateLimitExceededException::class, 'Rate limit exceeded for provider');

    fleetopsAbstractProviderUseArrayCache();
    Http::swap(new HttpFactory());
    Http::fake(fn () => Http::response(['error' => true], 503));

    $provider = new FleetOpsAbstractTelematicProviderProbe();
    $provider->connect(fleetopsAbstractProviderTelematic());

    expect(fn () => $provider->requestForTest('GET', '/failure'))
        ->toThrow(Exception::class, 'Provider API request failed with status 503');
});

test('abstract provider caps gradual rate limit refill at burst size', function () {
    fleetopsAbstractProviderUseArrayCache();

    $provider = new FleetOpsAbstractTelematicProviderProbe();
    $provider->connect(fleetopsAbstractProviderTelematic());

    Cache::put($provider->rateLimitKeyForTest(), 7, 60);
    $provider->recordRequestForTest();

    expect(Cache::get($provider->rateLimitKeyForTest()))->toBe(8);

    Cache::put($provider->rateLimitKeyForTest(), 10, 60);
    $provider->recordRequestForTest();

    expect(Cache::get($provider->rateLimitKeyForTest()))->toBe(10);
});
