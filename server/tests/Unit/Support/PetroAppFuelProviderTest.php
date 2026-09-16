<?php

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Covers the PetroApp fuel provider against faked HTTP responses: connection
 * testing across success/failure/exception branches, paginated retrieval with
 * multi-page traversal and failure propagation, and transaction listing with
 * bill normalization.
 */
function fleetopsPetroAppFreshHttp(): void
{
    // Http::fake stubs accumulate on the cached facade instance; a fresh
    // factory per scenario keeps each fake isolated.
    Http::clearResolvedInstances();
    app()->forgetInstance(Illuminate\Http\Client\Factory::class);
}

function fleetopsPetroAppConnection(array $credentials = []): FuelProviderConnection
{
    $connection = new FuelProviderConnection();
    $connection->setRawAttributes([
        'uuid'         => 'conn-1',
        'company_uuid' => 'company-1',
        'provider'     => 'petroapp',
        'credentials'  => json_encode(array_merge([
            'base_url'  => 'https://petroapp.test/webservice',
            'api_token' => 'token-123',
        ], $credentials)),
    ], true);

    return $connection;
}

test('test connection reports success failure and exceptions', function () {
    fleetopsPetroAppFreshHttp();
    Http::fake([
        'https://petroapp.test/webservice/vehicles*' => Http::response(['data' => ['total' => 12, 'data' => []]], 200),
    ]);

    $provider = new PetroAppFuelProvider();
    $success  = $provider->testConnection(fleetopsPetroAppConnection());

    expect($success['success'])->toBeTrue()
        ->and($success['metadata']['total_vehicles'])->toBe(12);

    fleetopsPetroAppFreshHttp();
    Http::fake([
        'https://petroapp.test/webservice/vehicles*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);
    $failure = $provider->testConnection(fleetopsPetroAppConnection());
    expect($failure['success'])->toBeFalse()
        ->and($failure['message'])->toBe('Unauthorized')
        ->and($failure['metadata']['status'])->toBe(401);

    fleetopsPetroAppFreshHttp();
    Http::fake(function () {
        throw new Exception('network unreachable');
    });
    $exception = $provider->testConnection(fleetopsPetroAppConnection());
    expect($exception['success'])->toBeFalse()
        ->and($exception['message'])->toBe('network unreachable');
});

test('paginated get traverses pages and lists vehicles and stations', function () {
    fleetopsPetroAppFreshHttp();
    Http::fake([
        'https://petroapp.test/webservice/vehicles*' => Http::sequence()
            ->push(['data' => ['data' => [['id' => 1]], 'last_page' => 2, 'next_page_url' => 'x']], 200)
            ->push(['data' => ['data' => [['id' => 2]], 'last_page' => 2, 'next_page_url' => null]], 200),
        'https://petroapp.test/webservice/petroapp_locations*' => Http::response(['locs' => [['id' => 'station-1']]], 200),
    ]);

    $provider = new PetroAppFuelProvider();

    $vehicles = $provider->listVehicles(fleetopsPetroAppConnection());
    expect($vehicles)->toHaveCount(2);

    $stations = $provider->listStations(fleetopsPetroAppConnection());
    expect($stations)->toHaveCount(1)
        ->and($stations->first()['id'])->toBe('station-1');
});

test('paginated get raises runtime errors on failed responses', function () {
    fleetopsPetroAppFreshHttp();
    Http::fake([
        'https://petroapp.test/webservice/vehicles*' => Http::response(['message' => 'Server exploded'], 500),
    ]);

    $provider = new PetroAppFuelProvider();

    expect(fn () => $provider->listVehicles(fleetopsPetroAppConnection()))
        ->toThrow(RuntimeException::class, 'Server exploded');
});

test('list transactions normalizes bills with identifiers and amounts', function () {
    fleetopsPetroAppFreshHttp();
    Http::fake([
        'https://petroapp.test/webservice/bills*' => Http::response([
            'data' => [
                'data' => [
                    [
                        'id'              => 991,
                        'bill_date'       => '2026-07-01 10:00:00',
                        'vehicle_id'      => 'VH 12',
                        'internal_number' => 'INT-9',
                        'plate_snum'      => 'SGX-1',
                        'station_name'    => 'Station A',
                        'num_of_liters'   => '35.5',
                        'cost'            => '120.75',
                    ],
                    [
                        'bill_date'    => '2026-07-02 11:00:00',
                        'station_name' => 'Station B',
                        'cost'         => '80.00',
                    ],
                ],
                'last_page' => 1,
            ],
        ], 200),
    ]);

    $provider     = new PetroAppFuelProvider();
    $transactions = $provider->listTransactions(
        fleetopsPetroAppConnection(['auth_type' => 'ws_sk_header']),
        Carbon::parse('2026-07-01'),
        Carbon::parse('2026-07-31')
    );

    expect($transactions)->toHaveCount(2)
        ->and($transactions->first()['provider'])->toBe('petroapp')
        ->and($transactions->first()['provider_transaction_id'])->toBe('991')
        ->and($transactions->last()['provider_transaction_id'])->not->toBeEmpty();
});

test('provider identity and header variants resolve correctly', function () {
    $provider = new PetroAppFuelProvider();

    expect($provider->key())->toBe('petroapp')
        ->and($provider->name())->toBe('PetroApp');

    $probe = new class extends PetroAppFuelProvider {
        public function exposeHeaders(FuelProviderConnection $connection): array
        {
            return $this->headers($connection);
        }

        public function exposeBaseUrl(FuelProviderConnection $connection): string
        {
            return $this->baseUrl($connection);
        }
    };

    $bearer = $probe->exposeHeaders(fleetopsPetroAppConnection());
    expect($bearer['Authorization'])->toBe('Bearer token-123');

    $wsSk = $probe->exposeHeaders(fleetopsPetroAppConnection(['auth_type' => 'ws_sk_header']));
    expect($wsSk['WS-SK'])->toBe('token-123')
        ->and($wsSk)->not->toHaveKey('Authorization');

    expect($probe->exposeBaseUrl(fleetopsPetroAppConnection()))->toBe('https://petroapp.test/webservice');
});

test('environment selects the endpoint for connection tests and imports', function (string $environment, string $baseUrl) {
    fleetopsPetroAppFreshHttp();
    Http::preventStrayRequests();
    Http::fake([
        $baseUrl . '/vehicles*' => Http::response(['success' => true, 'data' => ['data' => [], 'meta' => ['total' => 123]]]),
        $baseUrl . '/bills*'    => Http::response(['success' => true, 'data' => ['data' => []]]),
    ]);
    $connection              = fleetopsPetroAppConnection(['base_url' => '  ', 'auth_type' => 'ws_sk_header']);
    $connection->environment = $environment;
    $provider                = new PetroAppFuelProvider();

    expect($provider->testConnection($connection))
        ->toMatchArray(['success' => true]);
    expect($provider->testConnection($connection)['metadata'])->toMatchArray(['total_vehicles' => 123, 'environment' => $environment, 'auth_type' => 'ws_sk_header']);
    expect($provider->listTransactions($connection, Carbon::parse('2026-09-08'), Carbon::parse('2026-09-15')))->toBeEmpty();
    Http::assertSent(fn ($request) => $request->url() === $baseUrl . '/vehicles?page=1'
        && $request->hasHeader('WS-SK', 'token-123') && !$request->hasHeader('Authorization'));
    Http::assertSent(fn ($request) => str_starts_with($request->url(), $baseUrl . '/bills?')
        && $request['from'] === '2026-09-08' && $request['to'] === '2026-09-15' && $request['lang'] === 'en');
})->with([
    ['sandbox', 'https://app-public.staging.petroapp.app/webservice'],
    ['production', 'https://app.petroapp.com.sa/webservice'],
]);

test('HTTP 200 provider errors and malformed payloads fail tests and imports', function ($payload) {
    fleetopsPetroAppFreshHttp();
    Http::fake(['*' => Http::response($payload, 200)]);
    $provider   = new PetroAppFuelProvider();
    $connection = fleetopsPetroAppConnection();

    expect($provider->testConnection($connection)['success'])->toBeFalse();
    expect(fn () => $provider->listTransactions($connection, Carbon::now()->subDay(), Carbon::now()))->toThrow(RuntimeException::class);
})->with([
    'missing secret'  => [['status' => false, 'message' => 'Secret key is not found']],
    'invalid bearer'  => [['success' => false, 'message' => 'Unauthenticated.', 'data' => null]],
    'false with data' => [['success' => false, 'data' => ['data' => []]]],
    'numeric status'  => [['status' => 0, 'data' => ['data' => []]]],
    'HTML'            => ['<html>Login</html>'],
    'missing data'    => [['message' => 'Secret key is not found']],
]);

test('vehicle resource pagination follows meta and links without following insecure URLs', function () {
    fleetopsPetroAppFreshHttp();
    Http::preventStrayRequests();
    Http::fake([
        'https://petroapp.test/webservice/vehicles?page=1' => Http::response([
            'success' => true,
            'data'    => ['data' => [['vehicle_id' => 1]], 'meta' => ['last_page' => 2], 'links' => ['next' => 'http://petroapp.test/webservice/vehicles?page=2']],
        ]),
        'https://petroapp.test/webservice/vehicles?page=2' => Http::response([
            'success' => true,
            'data'    => ['data' => [['vehicle_id' => 2]], 'meta' => ['last_page' => 2], 'links' => ['next' => null]],
        ]),
    ]);

    expect((new PetroAppFuelProvider())->listVehicles(fleetopsPetroAppConnection())->pluck('vehicle_id')->all())->toBe([1, 2]);
    Http::assertSentCount(2);
});

test('legacy token-only forms retry WS-SK only when PetroApp requests the missing secret key', function () {
    fleetopsPetroAppFreshHttp();
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if ($request->hasHeader('WS-SK', 'token-123')) {
            expect($request->hasHeader('Authorization'))->toBeFalse();

            return Http::response(['success' => true, 'data' => ['data' => [['id' => 1]], 'total' => 1]]);
        }

        expect($request->hasHeader('Authorization', 'Bearer token-123'))->toBeTrue();

        return Http::response(['status' => false, 'message' => 'Secret key is not found']);
    });
    $connection = fleetopsPetroAppConnection(['api_token' => " token-123\n"]);
    $provider   = new PetroAppFuelProvider();
    $result     = $provider->testConnection($connection);
    expect($result['success'])->toBeTrue()
        ->and($result['metadata']['auth_type'])->toBe('ws_sk_header')
        ->and(data_get($connection->credentials, 'auth_type'))->toBeNull();
    expect($provider->listVehicles($connection))->toHaveCount(1);
    Http::assertSentCount(4);
});

test('explicit authentication is never changed after a missing secret error', function (string $authType) {
    fleetopsPetroAppFreshHttp();
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Secret key is not found'])]);
    $result = (new PetroAppFuelProvider())->testConnection(fleetopsPetroAppConnection(['auth_type' => $authType]));
    expect($result['success'])->toBeFalse()->and($result['metadata']['auth_type'])->toBe($authType);
    Http::assertSentCount(1);
})->with(['bearer_token', 'ws_sk_header']);

test('working legacy bearer tokens are not retried', function () {
    fleetopsPetroAppFreshHttp();
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['data' => [], 'total' => 0]])]);
    $result = (new PetroAppFuelProvider())->testConnection(fleetopsPetroAppConnection());
    expect($result['success'])->toBeTrue()->and($result['metadata']['auth_type'])->toBe('bearer_token');
    Http::assertSentCount(1);
});

test('PetroApp bill numbers remain stable when a bill is corrected and split plates are combined', function () {
    $provider = new class extends PetroAppFuelProvider {
        public function normalize(array $bill): array
        {
            return $this->normalizeBill($bill);
        }
    };
    $bill      = ['bill_number' => 32549086, 'plate_letter' => 'ABC', 'plate_snum' => '1234', 'cost' => '3.00', 'num_of_liters' => '4'];
    $original  = $provider->normalize($bill);
    $corrected = $provider->normalize(array_merge($bill, ['cost' => '4.50']));
    expect($original['provider_transaction_id'])->toBe('32549086')
        ->and($corrected['provider_transaction_id'])->toBe($original['provider_transaction_id'])
        ->and($original['plate_number'])->toBe('ABC 1234')
        ->and($corrected['amount'])->toBe(450)
        ->and($provider->normalize(array_merge($bill, ['plate_snum' => 'ABC 1234']))['plate_number'])->toBe('ABC 1234');
});

test('pagination limits fail visibly instead of returning a silently incomplete import', function () {
    fleetopsPetroAppFreshHttp();
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['success' => true, 'data' => ['data' => [['bill_number' => 1]], 'last_page' => 3]])]);
    expect(fn () => (new PetroAppFuelProvider())->listTransactions(fleetopsPetroAppConnection(), Carbon::parse('2026-06-23'), Carbon::parse('2026-06-23'), ['max_pages' => 1]))
        ->toThrow(RuntimeException::class, 'Choose a shorter date range');
    Http::assertSentCount(1);
});
