<?php

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Illuminate\Support\Facades\Http;

/**
 * Covers the AFAQY provider HTTP transport with faked responses:
 * authentication success, failure and missing-token errors, authenticated
 * posts with token-rejection refresh retries, exhausted retries without
 * refresh credentials, failed requests, provider error context extraction,
 * byte-count parsing, ignition and fuel extraction, and sensor identity
 * resolution.
 */
class FleetOpsAfaqyTransportProbe extends AfaqyProvider
{
    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
        if (!empty($credentials['token'])) {
            $this->setToken($credentials['token']);
        }
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsAfaqyTransportBoot(): void
{
    app()->instance('log', new class {
        public function __call($method, $arguments)
        {
            return null;
        }
    });
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
}

function fleetopsAfaqyTransportProbe(array $credentials = []): FleetOpsAfaqyTransportProbe
{
    $probe = new FleetOpsAfaqyTransportProbe();
    $probe->setCredentialsForTest($credentials);

    return $probe;
}

test('authentication resolves tokens and raises detailed failures', function () {
    fleetopsAfaqyTransportBoot();

    $missing = fleetopsAfaqyTransportProbe([]);
    expect(fn () => $missing->callHelper('authenticate'))->toThrow(InvalidArgumentException::class);

    Http::fake(['*/auth/login' => Http::response(['data' => ['token' => 'fresh-token']], 200)]);
    $probe = fleetopsAfaqyTransportProbe(['username' => 'user', 'password' => 'secret']);
    expect($probe->callHelper('authenticate'))->toBe('fresh-token');

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*/auth/login' => Http::response(['message' => 'denied', 'code' => 'AUTH'], 500)]);
    expect(fn () => $probe->callHelper('authenticate'))->toThrow(TelematicProviderException::class, 'authentication failed');

    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*/auth/login' => Http::response(['data' => []], 200)]);
    expect(fn () => $probe->callHelper('authenticate'))->toThrow(TelematicProviderException::class, 'did not return a token');
});

test('authenticated posts refresh rejected tokens and propagate failures', function () {
    fleetopsAfaqyTransportBoot();

    // First call rejected, refresh logs in, retry succeeds
    Http::fake([
        '*/auth/login' => Http::response(['data' => ['token' => 'refreshed-token']], 200),
        '*/units/list' => Http::sequence()
            ->push(['message' => 'expired'], 401)
            ->push(['data' => ['ok' => true]], 200),
    ]);
    $probe  = fleetopsAfaqyTransportProbe(['token' => 'stale-token', 'username' => 'user', 'password' => 'secret']);
    $result = $probe->callHelper('afaqyPost', '/units/list', ['data' => []]);
    expect($result['data']['ok'])->toBeTrue();

    // Token rejected without refresh credentials raises immediately
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*/units/list' => Http::response(['error' => ['message' => 'expired', 'code' => 401]], 401)]);
    $tokenOnly = fleetopsAfaqyTransportProbe(['token' => 'stale-token']);
    expect(fn () => $tokenOnly->callHelper('afaqyPost', '/units/list'))
        ->toThrow(TelematicProviderException::class, 'credentials are required');

    // Non-auth failures raise with provider context
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::fake(['*/units/list' => Http::response(['error_description' => 'boom'], 500)]);
    try {
        $tokenOnly->callHelper('afaqyPost', '/units/list');
        expect(false)->toBeTrue();
    } catch (TelematicProviderException $e) {
        expect($e->getMessage())->toContain('failed with status 500');
    }
});

test('transport errors and helper extractors resolve metadata', function () {
    fleetopsAfaqyTransportBoot();

    Http::fake(function () {
        throw new Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out with 512 bytes received');
    });
    $probe = fleetopsAfaqyTransportProbe(['token' => 'token-1']);
    try {
        $probe->callHelper('afaqyPost', '/units/list');
        expect(false)->toBeTrue();
    } catch (TelematicProviderException $e) {
        expect($e->getMessage())->toContain('timed out');
    }

    expect($probe->callHelper('extractBytesReceived', 'Operation timed out with 512 bytes received'))->toBe(512)
        ->and($probe->callHelper('extractBytesReceived', 'no byte info'))->toBeNull();

    expect($probe->callHelper('extractIgnition', ['last_update' => ['params' => ['acc' => '1']]]))->toBeTrue()
        ->and($probe->callHelper('extractIgnition', ['counters' => ['last_acc' => 0]]))->toBeFalse()
        ->and($probe->callHelper('extractIgnition', []))->toBeNull();

    expect($probe->callHelper('extractFuelLevel', ['fc' => ['level' => 55]]))->toBe(55)
        ->and($probe->callHelper('extractFuelLevel', []))->toBeNull();

    expect($probe->callHelper('resolveSensorIdentity', ['sensor_id' => 's-9']))->toBe('s-9')
        ->and($probe->callHelper('resolveSensorIdentity', ['sensor' => ['name' => 'Temp']]))->toBe('Temp')
        ->and($probe->callHelper('resolveSensorIdentity', []))->toBeNull();

    expect($probe->callHelper('resolveSensorName', ['param' => 'fuel'], 'fallback'))->toBe('fuel')
        ->and($probe->callHelper('resolveSensorName', [], 'fallback'))->toBe('fallback');
});
