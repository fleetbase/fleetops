<?php

use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\GeotabProvider;
use Fleetbase\FleetOps\Support\Telematics\Providers\SafeeProvider;
use Illuminate\Support\Facades\Http;

/**
 * The provider transport seams that behaviour tests replace with response
 * queues, plus the two authentication arms that only a real response can reach.
 *
 * These live in their own file because `Http::fake()` state leaks between tests
 * in the shared harness — the same reason `TelematicsHardeningTest` carries a
 * skipped case.
 */
class FleetOpsProviderTransportGeotabProbe extends GeotabProvider
{
    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

class FleetOpsProviderTransportAfaqyProbe extends AfaqyProvider
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

class FleetOpsProviderTransportSafeeProbe extends SafeeProvider
{
    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsProviderTransportBoot(): void
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

test('the geotab transport posts the payload and returns the decoded body', function () {
    fleetopsProviderTransportBoot();
    $recorded = [];
    Http::fake(function ($request) use (&$recorded) {
        $recorded[] = $request;

        return Http::response(['result' => ['id' => 'device-1']], 200);
    });

    $provider = new FleetOpsProviderTransportGeotabProbe();
    $provider->setCredentialsForTest(['database' => 'fleetbase-db']);

    // Behaviour tests override this seam with a canned response queue, so the
    // real body only runs when it is invoked directly
    $result = $provider->callHelper('postGeotab', ['method' => 'Get', 'params' => ['typeName' => 'Device']]);

    expect($result)->toBe(['result' => ['id' => 'device-1']])
        ->and($recorded)->toHaveCount(1)
        ->and($recorded[0]->data())->toBe(['method' => 'Get', 'params' => ['typeName' => 'Device']]);
});

test('afaqy reports a rejected token differently once a refresh has already been spent', function () {
    fleetopsProviderTransportBoot();
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    // Username and password are present, so the provider could refresh — but
    // the retry has already been used, which is the arm being covered here
    $probe = new FleetOpsProviderTransportAfaqyProbe();
    $probe->setCredentialsForTest([
        'token'    => 'stale-token',
        'username' => 'user',
        'password' => 'secret',
    ]);

    expect(fn () => $probe->callHelper('authenticatedPost', '/devices/list', [], false, false))
        ->toThrow(TelematicProviderException::class, 'AFAQY token rejected after refresh with status 401');

    // Without refresh credentials the message names the missing ones instead
    $credentialless = new FleetOpsProviderTransportAfaqyProbe();
    $credentialless->setCredentialsForTest(['token' => 'stale-token']);

    expect(fn () => $credentialless->callHelper('authenticatedPost', '/devices/list', [], false, false))
        ->toThrow(TelematicProviderException::class, 'username/password credentials are required');
});

test('safee authentication rejects a successful response that carries no token', function () {
    fleetopsProviderTransportBoot();
    Http::fake(['*' => Http::response(['expires_in' => 300], 200)]);

    $probe = new FleetOpsProviderTransportSafeeProbe();
    $probe->setCredentialsForTest([
        'realm_id'      => 'fleetbase',
        'client_id'     => 'client',
        'client_secret' => 'secret',
        'username'      => 'user',
        'password'      => 'password',
    ]);

    expect(fn () => $probe->callHelper('authenticate'))
        ->toThrow(RuntimeException::class, 'Safee authentication did not return an access token.');
});
