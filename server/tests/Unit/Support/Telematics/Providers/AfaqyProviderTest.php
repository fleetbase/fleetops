<?php

use Fleetbase\FleetOps\Support\Telematics\Providers\AfaqyProvider;

class FleetOpsAfaqyProviderUnitProbe extends AfaqyProvider
{
    public array $postCalls          = [];
    public array $responses          = [];
    public ?Throwable $postException = null;
    public ?string $authToken        = 'authenticated-token';
    public int $authentications      = 0;

    public function setCredentialsForTest(array $credentials): void
    {
        $this->credentials = $credentials;
    }

    public function credentialsForTest(): array
    {
        return $this->credentials;
    }

    public function headersForTest(): array
    {
        return $this->headers;
    }

    public function baseUrlForTest(): string
    {
        return $this->baseUrl;
    }

    public function prepareAuthenticationForTest(): void
    {
        $this->prepareAuthentication();
    }

    public function queuePostResponse(array $response): void
    {
        $this->responses[] = $response;
    }

    protected function authenticate(): string
    {
        $this->authentications++;

        if (!$this->authToken) {
            throw new InvalidArgumentException('AFAQY username/password or token is required.');
        }

        return $this->authToken;
    }

    protected function afaqyPost(string $endpoint, array $payload = [], bool $tokenInQuery = false, ?int $timeout = null, ?int $connectTimeout = null): array
    {
        $this->postCalls[] = [$endpoint, $payload, $tokenInQuery, $timeout, $connectTimeout];

        if ($this->postException) {
            throw $this->postException;
        }

        return array_shift($this->responses) ?? [];
    }
}

function fleetopsAfaqyProviderUnit(array $credentials = []): FleetOpsAfaqyProviderUnitProbe
{
    $provider = new FleetOpsAfaqyProviderUnitProbe();
    $provider->setCredentialsForTest($credentials);

    return $provider;
}

test('afaqy provider prepares token authentication and custom base urls', function () {
    $staticToken = fleetopsAfaqyProviderUnit([
        'base_url' => 'https://afaqy.example.test/',
        'token'    => 'static-token',
    ]);
    $staticToken->prepareAuthenticationForTest();

    expect($staticToken->baseUrlForTest())->toBe('https://afaqy.example.test')
        ->and($staticToken->credentialsForTest()['token'])->toBe('static-token')
        ->and($staticToken->headersForTest())->toBe([
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer static-token',
        ])
        ->and($staticToken->authentications)->toBe(0);

    $refreshable = fleetopsAfaqyProviderUnit([
        'username' => 'unit-user',
        'password' => 'unit-secret',
    ]);
    $refreshable->prepareAuthenticationForTest();

    expect($refreshable->credentialsForTest()['token'])->toBe('authenticated-token')
        ->and($refreshable->authentications)->toBe(1);

    expect(fn () => fleetopsAfaqyProviderUnit()->prepareAuthenticationForTest())
        ->toThrow(InvalidArgumentException::class, 'AFAQY username/password or token is required.');
});

test('afaqy provider tests connections with timeout settings and failure metadata', function () {
    $success = fleetopsAfaqyProviderUnit(['token' => 'static-token']);
    $success->queuePostResponse([
        'data'        => [['id' => 'unit-1'], ['id' => 'unit-2']],
        'status_code' => 200,
    ]);

    expect($success->testConnection(['token' => 'static-token']))->toBe([
        'success'  => true,
        'message'  => 'Connection successful',
        'metadata' => [
            'units_count' => 2,
            'status_code' => 200,
        ],
    ])
        ->and($success->postCalls)->toHaveCount(1)
        ->and($success->postCalls[0][0])->toBe('/units/lists')
        ->and($success->postCalls[0][2])->toBeTrue()
        ->and($success->postCalls[0][3])->toBe(30)
        ->and($success->postCalls[0][4])->toBe(10);

    $failure                = fleetopsAfaqyProviderUnit();
    $failure->postException = new RuntimeException('provider unavailable');

    expect($failure->testConnection(['token' => 'static-token']))->toBe([
        'success'  => false,
        'message'  => 'provider unavailable',
        'metadata' => [],
    ]);
});

test('afaqy provider fetches paginated devices details and credential schema', function () {
    $provider = fleetopsAfaqyProviderUnit(['token' => 'static-token']);
    $provider->queuePostResponse([
        'data' => [
            ['_id' => 'unit-1', 'name' => 'Unit 1'],
            ['_id' => 'unit-2', 'name' => 'Unit 2'],
        ],
        'pagination' => [
            'offset'       => 10,
            'limit'        => 2,
            'resultCount'  => 2,
            'filtersCount' => 20,
            'allCount'     => 30,
        ],
    ]);
    $provider->queuePostResponse([
        'data' => ['_id' => 'unit-1', 'name' => 'Unit 1'],
    ]);

    $devices = $provider->fetchDevices([
        'limit'   => 1000,
        'cursor'  => 10,
        'filters' => [],
        'address' => true,
    ]);
    $details = $provider->fetchDeviceDetails('unit-1');

    expect($devices)->toMatchArray([
        'devices'     => [
            ['_id' => 'unit-1', 'name' => 'Unit 1'],
            ['_id' => 'unit-2', 'name' => 'Unit 2'],
        ],
        'next_cursor' => 12,
        'has_more'    => true,
        'pagination'  => [
            'allCount'     => 30,
            'filtersCount' => 20,
            'resultCount'  => 2,
            'offset'       => 10,
            'limit'        => 2,
        ],
    ])
        ->and($details)->toBe(['_id' => 'unit-1', 'name' => 'Unit 1'])
        ->and($provider->postCalls[0][0])->toBe('/units/lists')
        ->and($provider->postCalls[0][1]['data']['limit'])->toBe(500)
        ->and($provider->postCalls[0][1]['data']['offset'])->toBe(10)
        ->and($provider->postCalls[0][1]['data']['filters'])->toBeInstanceOf(stdClass::class)
        ->and($provider->postCalls[0][1]['data']['address'])->toBeTrue()
        ->and($provider->postCalls[0][2])->toBeTrue()
        ->and($provider->postCalls[1])->toBe(['/units/view', ['data' => ['id' => 'unit-1']], false, null, null]);

    $schema = $provider->getCredentialSchema();

    expect(array_column($schema, 'name'))->toBe(['base_url', 'username', 'password', 'token'])
        ->and($schema[0]['default_value'])->toBe('https://api.afaqy.sa')
        ->and($schema[1]['validation'])->toBe('required_without:token|string')
        ->and($schema[3]['validation'])->toBe('required_without:username|string');
});
