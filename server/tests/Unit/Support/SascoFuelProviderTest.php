<?php

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\SascoFuelProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Covers the SASCO B2B fuel provider against faked HTTP responses: username and
 * password login with cached JWTs, one-shot re-authentication on 401, connection
 * testing, current_page pagination bounded by totalItems, and driver transaction
 * normalization.
 */
const SASCO_TEST_BASE = 'https://sasco.test';

function fleetopsSascoFresh(): void
{
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
    Cache::clear();
}

function fleetopsSascoConnection(array $credentials = [], ?string $environment = null): FuelProviderConnection
{
    $connection = new FuelProviderConnection();
    $connection->setRawAttributes([
        'uuid'         => 'conn-sasco',
        'company_uuid' => 'company-1',
        'provider'     => 'sasco',
        'environment'  => $environment,
        'credentials'  => json_encode(array_merge([
            'base_url' => SASCO_TEST_BASE,
            'username' => 'fleet@example.test',
            'password' => 'secret',
        ], $credentials)),
    ], true);

    return $connection;
}

function fleetopsSascoJwt(?int $exp): string
{
    $segment = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $segment(['alg' => 'RS256']) . '.' . $segment($exp ? ['exp' => $exp] : ['sub' => 'user']) . '.signature';
}

function fleetopsSascoInvoke(SascoFuelProvider $provider, string $method, ...$arguments)
{
    $reflection = new ReflectionMethod($provider, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($provider, ...$arguments);
}

function fleetopsSascoTransaction(array $overrides = []): array
{
    return array_merge([
        'transactionNumber' => '27307485',
        'stationName'       => ' Station  North ',
        'noOfLitters'       => 42.735,
        'fuelType'          => '95',
        'driverName'        => 'Annabella Francesconi',
        'driverPhone'       => '518596945',
        'createdAt'         => '2025-11-03T17:19:49.595558+03:00',
        'amount'            => 100.5,
        'fuelImageUrl'      => 'https://storage.example.test/receipt.jpg',
        'vehiclePlate'      => [
            'plateNumber'       => '1240',
            'firstPlateLetter'  => 'N',
            'secondPlateLetter' => 'X',
            'thirdPlateLetter'  => 'D',
        ],
        'type'   => 'Trip',
        'tripId' => null,
        'period' => 4,
    ], $overrides);
}

test('sasco provider identifies itself and resolves environment base urls', function () {
    $provider = new SascoFuelProvider();

    expect($provider->key())->toBe('sasco')
        ->and($provider->name())->toBe('SASCO')
        ->and(fleetopsSascoInvoke($provider, 'baseUrl', fleetopsSascoConnection(['base_url' => ''], 'sandbox')))->toBe('https://loyalty-api-qc.sasco.sa')
        ->and(fleetopsSascoInvoke($provider, 'baseUrl', fleetopsSascoConnection(['base_url' => ''])))->toBe('https://loyalty-api.sasco.com.sa')
        ->and(fleetopsSascoInvoke($provider, 'baseUrl', fleetopsSascoConnection(['base_url' => ' https://custom.test/root/ '])))->toBe('https://custom.test/root');
});

test('sasco authenticate caches the access token until shortly before it expires', function () {
    fleetopsSascoFresh();
    Carbon::setTestNow(Carbon::createFromTimestamp(1_000_000));
    $token = fleetopsSascoJwt(1_000_000 + 7200);
    Http::fake([SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login' => Http::response(['accessToken' => $token, 'loginSucces' => true], 200)]);

    $provider   = new SascoFuelProvider();
    $connection = fleetopsSascoConnection(['username' => ' fleet@example.test ']);
    $result     = $provider->authenticate($connection);

    expect($result)->toBe(['success' => true, 'message' => 'SASCO login successful.', 'token' => $token])
        ->and(Cache::get(fleetopsSascoInvoke($provider, 'tokenCacheKey', $connection)))->toBe($token)
        ->and(fleetopsSascoInvoke($provider, 'tokenTtl', $token))->toBe(7140)
        ->and(fleetopsSascoInvoke($provider, 'tokenTtl', fleetopsSascoJwt(null)))->toBe(3600)
        ->and(fleetopsSascoInvoke($provider, 'tokenTtl', 'not-a-jwt'))->toBe(3600)
        ->and(fleetopsSascoInvoke($provider, 'tokenTtl', fleetopsSascoJwt(1_000_000 + 10)))->toBe(1);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->data() === ['username' => 'fleet@example.test', 'password' => 'secret']);

    Carbon::setTestNow();
});

test('sasco authenticate reports rejected logins with the provider message or a fallback', function () {
    fleetopsSascoFresh();
    Http::fake([SASCO_TEST_BASE . '/*' => Http::sequence()
        ->push(['message' => 'Invalid user credentials'], 400)
        ->push(['loginSucces' => false], 200)
        ->push(['message' => ['not', 'a', 'string']], 500),
    ]);

    $provider = new SascoFuelProvider();
    $invalid  = $provider->authenticate(fleetopsSascoConnection());
    $noToken  = $provider->authenticate(fleetopsSascoConnection());
    $badBody  = $provider->authenticate(fleetopsSascoConnection());

    expect($invalid)->toBe(['success' => false, 'message' => 'Invalid user credentials', 'status' => 400])
        ->and($noToken['success'])->toBeFalse()
        ->and($noToken['message'])->toBe('SASCO login failed. Check the B2B admin username and password.')
        ->and($badBody['message'])->toBe('SASCO login failed. Check the B2B admin username and password.');
});

test('sasco test connection reports success failure and login errors', function () {
    fleetopsSascoFresh();
    Http::fake([
        SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login'              => Http::response(['accessToken' => fleetopsSascoJwt(null)], 200),
        SASCO_TEST_BASE . '/apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles*' => Http::sequence()
            ->push(['vehicles' => [['id' => 'vehicle-1']], 'totalItems' => 12], 200)
            ->push(['title' => 'Forbidden'], 403)
            ->push(['vehicles' => null], 200),
    ]);

    $provider = new SascoFuelProvider();
    $success  = $provider->testConnection(fleetopsSascoConnection([], 'sandbox'));
    $failure  = $provider->testConnection(fleetopsSascoConnection());
    $invalid  = $provider->testConnection(fleetopsSascoConnection());

    expect($success)->toBe([
        'success'  => true,
        'message'  => 'SASCO connection successful.',
        'metadata' => ['environment' => 'sandbox', 'host' => 'sasco.test', 'status' => 200, 'total_vehicles' => 12],
    ])
        ->and($failure['success'])->toBeFalse()
        ->and($failure['message'])->toBe('Forbidden')
        ->and($failure['metadata'])->toBe(['environment' => 'production', 'host' => 'sasco.test', 'status' => 403])
        ->and($invalid['message'])->toBe('Unable to connect to SASCO.');

    Http::assertSentCount(4);

    fleetopsSascoFresh();
    Http::fake([SASCO_TEST_BASE . '/*' => Http::response(['error' => 'Account locked'], 401)]);
    $locked = $provider->testConnection(fleetopsSascoConnection());

    expect($locked['success'])->toBeFalse()
        ->and($locked['message'])->toBe('Account locked')
        ->and($locked['metadata'])->toBe(['environment' => 'production', 'host' => 'sasco.test']);
});

test('sasco requests reuse the cached token and log in again once after a 401', function () {
    fleetopsSascoFresh();
    Http::fake([
        SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login' => Http::sequence()
            ->push(['accessToken' => 'token-1'], 200)
            ->push(['accessToken' => 'token-2'], 200),
        SASCO_TEST_BASE . '/apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles*' => Http::sequence()
            ->push(['vehicles' => [['id' => 'a']], 'totalItems' => 1], 200)
            ->push(['message' => 'expired'], 401)
            ->push(['vehicles' => [['id' => 'b']], 'totalItems' => 1], 200),
    ]);

    $provider = new SascoFuelProvider();
    $first    = $provider->listVehicles(fleetopsSascoConnection());
    $second   = $provider->listVehicles(fleetopsSascoConnection());

    $authorizations = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'GET')
        ->map(fn (Request $request) => $request->header('Authorization')[0])
        ->values()
        ->all();

    expect($first->pluck('id')->all())->toBe(['a'])
        ->and($second->pluck('id')->all())->toBe(['b'])
        ->and($authorizations)->toBe(['Bearer token-1', 'Bearer token-1', 'Bearer token-2']);
});

test('sasco requests raise the login error when no token can be obtained', function () {
    fleetopsSascoFresh();
    Http::fake([SASCO_TEST_BASE . '/*' => Http::response(['message' => 'Invalid user credentials'], 400)]);

    expect(fn () => (new SascoFuelProvider())->listVehicles(fleetopsSascoConnection()))
        ->toThrow(RuntimeException::class, 'Invalid user credentials');
});

test('sasco pagination walks current pages until totalItems and guards the page limit', function () {
    fleetopsSascoFresh();
    Http::fake([
        SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login'              => Http::response(['accessToken' => 'token'], 200),
        SASCO_TEST_BASE . '/apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles*' => Http::sequence()
            ->push(['vehicles' => [['id' => 1], ['id' => 2]], 'totalItems' => 3], 200)
            ->push(['vehicles' => [['id' => 3]], 'totalItems' => 3], 200)
            ->push(['vehicles' => [], 'totalItems' => 9], 200)
            ->push(['vehicles' => [['id' => 4]], 'totalItems' => 5], 200),
    ]);

    $provider = new SascoFuelProvider();
    $vehicles = $provider->listVehicles(fleetopsSascoConnection(), ['page_size' => 2, 'SearchKey' => 'NXD']);
    $empty    = $provider->listVehicles(fleetopsSascoConnection());

    expect($vehicles->pluck('id')->all())->toBe([1, 2, 3])
        ->and($empty->all())->toBe([]);

    $queries = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'GET')
        ->map(fn (Request $request) => parse_url($request->url(), PHP_URL_QUERY))
        ->values()
        ->all();

    expect($queries[0])->toBe('SearchKey=NXD&Page_size=2&Current_page=1')
        ->and($queries[1])->toBe('SearchKey=NXD&Page_size=2&Current_page=2')
        ->and($queries[2])->toBe('Page_size=100&Current_page=1');

    expect(fn () => $provider->listVehicles(fleetopsSascoConnection(), ['page' => 3, 'max_pages' => 3]))
        ->toThrow(RuntimeException::class, 'SASCO returned more pages than the sync limit.');
});

test('sasco pagination raises runtime errors for failed and malformed responses', function () {
    fleetopsSascoFresh();
    Http::fake([
        SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login'              => Http::response(['accessToken' => 'token'], 200),
        SASCO_TEST_BASE . '/apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles*' => Http::sequence()
            ->push('gateway timeout', 504)
            ->push(['data' => []], 200),
    ]);

    $provider = new SascoFuelProvider();

    expect(fn () => $provider->listVehicles(fleetopsSascoConnection()))
        ->toThrow(RuntimeException::class, 'SASCO apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles request failed.')
        ->and(fn () => $provider->listVehicles(fleetopsSascoConnection()))
        ->toThrow(RuntimeException::class, 'SASCO apigateway/api/loyaltycore/b2bexternaladmin/b2bvehicles returned an invalid response.');
});

test('sasco list transactions filters by date and normalizes driver transactions', function () {
    fleetopsSascoFresh();
    Http::fake([
        SASCO_TEST_BASE . '/apigateway/api/identity/user/b2badmin-login'                   => Http::response(['accessToken' => 'token'], 200),
        SASCO_TEST_BASE . '/apigateway/api/analytics/v1/b2bexternal/drivers-transactions*' => Http::sequence()
            ->push([
                'driverTransactions' => [
                    fleetopsSascoTransaction(),
                    fleetopsSascoTransaction(['transactionNumber' => ' ', 'tripId' => ' TRIP-7 ', 'vehiclePlate' => null, 'amount' => null]),
                ],
                'totalItems' => 2,
            ], 200)
            ->push(['driverTransactions' => [], 'totalItems' => 0], 200)
            ->push(['driverTransactions' => [], 'totalItems' => 0], 200),
    ]);

    $provider = new SascoFuelProvider();
    $from     = Carbon::parse('2025-10-30 08:00:00');
    $to       = Carbon::parse('2025-11-03 20:00:00');

    $transactions = $provider->listTransactions(fleetopsSascoConnection(), $from, $to);
    $provider->listTransactions(fleetopsSascoConnection(), $from, $to, ['is_trip' => true]);
    $provider->listTransactions(fleetopsSascoConnection(), $from, $to, ['is_trip' => false]);

    [$first, $second] = $transactions->all();

    expect($first)->toMatchArray([
        'provider'                => 'sasco',
        'provider_transaction_id' => '27307485',
        'plate_number'            => 'NXD 1240',
        'trip_number'             => null,
        'station_name'            => 'Station North',
        'volume'                  => 42.735,
        'metric_unit'             => 'l',
        'amount'                  => 10050,
        'currency'                => 'SAR',
        'normalized_payload'      => [
            'fuel_type'      => '95',
            'driver_name'    => 'Annabella Francesconi',
            'driver_phone'   => '518596945',
            'type'           => 'Trip',
            'period'         => 4,
            'fuel_image_url' => 'https://storage.example.test/receipt.jpg',
        ],
        'raw_payload' => fleetopsSascoTransaction(),
    ])
        ->and($first['transaction_at']->toIso8601String())->toBe('2025-11-03T17:19:49+03:00')
        ->and($second['provider_transaction_id'])->toHaveLength(64)
        ->and($second['plate_number'])->toBeNull()
        ->and($second['trip_number'])->toBe('TRIP-7')
        ->and($second['amount'])->toBeNull();

    $queries = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => $request->method() === 'GET')
        ->map(fn (Request $request) => parse_url($request->url(), PHP_URL_QUERY))
        ->values()
        ->all();

    expect($queries)->toBe([
        'FromDate=2025-10-30&ToDate=2025-11-03&Page_size=100&Current_page=1',
        'FromDate=2025-10-30&ToDate=2025-11-03&IsTrip=true&Page_size=100&Current_page=1',
        'FromDate=2025-10-30&ToDate=2025-11-03&IsTrip=false&Page_size=100&Current_page=1',
    ]);
});

test('sasco plate numbers tolerate partial plates', function () {
    $provider = new SascoFuelProvider();

    expect(fleetopsSascoInvoke($provider, 'plateNumber', ['plateNumber' => '435']))->toBe('435')
        ->and(fleetopsSascoInvoke($provider, 'plateNumber', ['firstPlateLetter' => 'R', 'secondPlateLetter' => ' ', 'thirdPlateLetter' => 'R']))->toBe('RR')
        ->and(fleetopsSascoInvoke($provider, 'plateNumber', []))->toBeNull();
});

test('sasco is registered as a native fuel provider', function () {
    $providers = require __DIR__ . '/../../../config/fuel-providers.php';
    $sasco     = collect($providers['providers'])->firstWhere('key', 'sasco');

    expect($sasco['driver_class'])->toBe(SascoFuelProvider::class)
        ->and(collect($sasco['required_fields'])->pluck('name')->all())->toBe(['username', 'password', 'base_url'])
        ->and($sasco['metadata']['base_urls'])->toBe(SascoFuelProvider::BASE_URLS)
        ->and($sasco['capabilities'])->toBe(['vehicles', 'transactions']);
});
