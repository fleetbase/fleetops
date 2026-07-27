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
        'https://petroapp.test/webservice/vehicles*' => Http::response(['data' => ['total' => 12]], 200),
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
