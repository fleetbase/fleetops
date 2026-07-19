<?php

use Fleetbase\FleetOps\Contracts\TelematicProviderDescriptor;
use Fleetbase\FleetOps\Contracts\TelematicProviderInterface;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\Providers\AbstractProvider;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;

class TestTelematicProviderForRegistry extends AbstractProvider
{
    public function exposedCredentials(): array
    {
        return $this->credentials;
    }

    public function exposedRateLimitKey(): string
    {
        return $this->rateLimitKey();
    }

    protected function prepareAuthentication(): void
    {
        $this->headers['Authorization'] = 'Bearer ' . ($this->credentials['token'] ?? 'missing');
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
}

test('telematic provider registry stores filters and resolves provider drivers', function () {
    $registry = new TelematicProviderRegistry();

    $native = new TelematicProviderDescriptor([
        'key'                => 'native_provider',
        'label'              => 'Native Provider',
        'driver_class'       => TestTelematicProviderForRegistry::class,
        'supports_webhooks'  => true,
        'supports_discovery' => true,
    ]);
    $custom = new TelematicProviderDescriptor([
        'key'                => 'custom_provider',
        'label'              => 'Custom Provider',
        'type'               => 'custom',
        'driver_class'       => TestTelematicProviderForRegistry::class,
        'supports_webhooks'  => false,
        'supports_discovery' => false,
    ]);

    $registry->register($native);
    $registry->register($custom);

    expect($registry->has('native_provider'))->toBeTrue()
        ->and($registry->findByKey('native_provider'))->toBe($native)
        ->and($registry->all()->only(['native_provider', 'custom_provider'])->all())->toBe([
            'native_provider' => $native,
            'custom_provider' => $custom,
        ])
        ->and($registry->getWebhookProviders()->keys()->all())->toContain('native_provider')
        ->and($registry->getDiscoveryProviders()->keys()->all())->toContain('native_provider')
        ->and($registry->getNativeProviders()->keys()->all())->toContain('native_provider')
        ->and($registry->getCustomProviders()->keys()->all())->toContain('custom_provider')
        ->and($registry->resolve('native_provider'))->toBeInstanceOf(TestTelematicProviderForRegistry::class);
});

test('telematic provider registry reports invalid resolve states clearly', function () {
    $registry = new TelematicProviderRegistry();
    $registry->register(new TelematicProviderDescriptor([
        'key'   => 'missing_driver',
        'label' => 'Missing Driver',
    ]));
    $registry->register(new TelematicProviderDescriptor([
        'key'          => 'unknown_class',
        'label'        => 'Unknown Class',
        'driver_class' => 'Fleetbase\\FleetOps\\Tests\\MissingTelematicProvider',
    ]));
    $registry->register(new TelematicProviderDescriptor([
        'key'          => 'wrong_contract',
        'label'        => 'Wrong Contract',
        'driver_class' => stdClass::class,
    ]));

    expect(fn () => $registry->resolve('not_registered'))
        ->toThrow(InvalidArgumentException::class, "Provider 'not_registered' not found in registry.")
        ->and(fn () => $registry->resolve('missing_driver'))
        ->toThrow(InvalidArgumentException::class, "Provider 'missing_driver' does not have a driver class.")
        ->and(fn () => $registry->resolve('unknown_class'))
        ->toThrow(InvalidArgumentException::class, "Provider driver class 'Fleetbase\\FleetOps\\Tests\\MissingTelematicProvider' does not exist.")
        ->and(fn () => $registry->resolve('wrong_contract'))
        ->toThrow(InvalidArgumentException::class, 'Provider driver class must implement TelematicProviderInterface.');
});

test('abstract telematic provider resolves array and json credentials', function () {
    $arrayTelematic = new Telematic();
    $arrayTelematic->setRawAttributes([
        'uuid'        => 'telematic-array',
        'credentials' => ['token' => 'array-token'],
    ], true);

    $jsonTelematic = new Telematic();
    $jsonTelematic->setRawAttributes([
        'uuid'        => 'telematic-json',
        'credentials' => json_encode(['token' => 'json-token']),
    ], true);

    $emptyTelematic = new Telematic();
    $emptyTelematic->setRawAttributes([
        'uuid'        => 'telematic-empty',
        'credentials' => null,
    ], true);

    $provider = new TestTelematicProviderForRegistry();
    $provider->connect($arrayTelematic);
    expect($provider->exposedCredentials())->toBe(['token' => 'array-token'])
        ->and($provider->exposedRateLimitKey())->toBe('rate_limit:TestTelematicProviderForRegistry:telematic-array');

    $provider->connect($jsonTelematic);
    expect($provider->exposedCredentials())->toBe(['token' => 'json-token']);

    $provider->connect($emptyTelematic);
    expect($provider->exposedCredentials())->toBe([]);
});

test('abstract telematic provider exposes conservative default capabilities', function () {
    $provider = new TestTelematicProviderForRegistry();

    expect($provider)->toBeInstanceOf(TelematicProviderInterface::class)
        ->and($provider->supportsWebhooks())->toBeFalse()
        ->and($provider->supportsDiscovery())->toBeTrue()
        ->and($provider->getRateLimits())->toBe([
            'requests_per_minute' => 60,
            'burst_size'          => 10,
        ])
        ->and($provider->validateWebhookSignature('payload', 'signature', []))->toBeFalse()
        ->and($provider->processWebhook(['event' => 'test']))->toBe([
            'devices' => [],
            'events'  => [],
            'sensors' => [],
        ])
        ->and($provider->getCredentialSchema())->toBe([]);
});
