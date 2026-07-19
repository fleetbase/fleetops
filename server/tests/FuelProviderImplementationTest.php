<?php

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\AbstractFuelProvider;
use Fleetbase\FleetOps\Support\FuelProviders\Providers\PetroAppFuelProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FuelProviderHarness extends AbstractFuelProvider
{
    public function key(): string
    {
        return 'harness';
    }

    public function name(): string
    {
        return 'Harness';
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        return $this->authenticate($connection);
    }

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection
    {
        return collect();
    }

    public function exposedBaseUrl(FuelProviderConnection $connection): string
    {
        return $this->baseUrl($connection);
    }

    public function exposedHash(array $payload): string
    {
        return $this->transactionHash($payload);
    }

    public function exposedMinorCurrencyUnit($amount): ?int
    {
        return $this->minorCurrencyUnit($amount);
    }

    public function exposedDateFrom($value): ?Carbon
    {
        return $this->dateFrom($value);
    }

    public function exposedCompactIdentifier($value): ?string
    {
        return $this->compactIdentifier($value);
    }
}

class PetroAppFuelProviderHarness extends PetroAppFuelProvider
{
    public function exposedBaseUrl(FuelProviderConnection $connection): string
    {
        return $this->baseUrl($connection);
    }

    public function exposedHeaders(FuelProviderConnection $connection): array
    {
        return $this->headers($connection);
    }

    public function exposedNormalizeBill(array $bill): array
    {
        return $this->normalizeBill($bill);
    }
}

function fuelProviderConnection(array $credentials = []): FuelProviderConnection
{
    $connection = new FuelProviderConnection();
    $connection->credentials = $credentials;

    return $connection;
}

test('abstract fuel provider exposes safe defaults and helper normalization', function () {
    $provider   = new FuelProviderHarness();
    $connection = fuelProviderConnection(['base_url' => 'https://fuel.example.test/api/']);

    expect($provider->authenticate($connection))->toBe([
        'success' => true,
        'message' => 'Authentication headers prepared.',
    ])
        ->and($provider->listVehicles($connection))->toBeInstanceOf(Collection::class)
        ->and($provider->listVehicles($connection)->all())->toBe([])
        ->and($provider->listStations($connection)->all())->toBe([])
        ->and($provider->pushTrip($connection, ['id' => 'trip-1']))->toBe([
            'success' => false,
            'message' => 'Provider does not support trip push.',
        ])
        ->and($provider->syncVehicle($connection, ['id' => 'vehicle-1']))->toBe([
            'success' => false,
            'message' => 'Provider does not support vehicle sync.',
        ])
        ->and($provider->webhookPayloadToTransaction($connection, ['event' => 'fuel']))->toBeNull()
        ->and($provider->exposedBaseUrl($connection))->toBe('https://fuel.example.test/api')
        ->and($provider->exposedHash(['b' => 2, 'a' => 1]))->toBe(hash('sha256', json_encode(['b' => 2, 'a' => 1])))
        ->and($provider->exposedMinorCurrencyUnit(null))->toBeNull()
        ->and($provider->exposedMinorCurrencyUnit(''))->toBeNull()
        ->and($provider->exposedMinorCurrencyUnit('12.345'))->toBe(1235)
        ->and($provider->exposedDateFrom(null))->toBeNull()
        ->and($provider->exposedDateFrom('2026-07-01')->toDateString())->toBe('2026-07-01')
        ->and($provider->exposedCompactIdentifier('  CARD   123  '))->toBe('CARD 123')
        ->and($provider->exposedCompactIdentifier('   '))->toBeNull();
});

test('petroapp provider resolves base urls auth headers and normalized bills', function () {
    $provider = new PetroAppFuelProviderHarness();

    $defaultConnection = fuelProviderConnection(['api_token' => 'token-1']);
    $customConnection  = fuelProviderConnection([
        'base_url'  => 'https://petroapp.example.test/root/',
        'auth_type' => 'ws_sk_header',
        'api_key'   => 'key-1',
        'version'   => 'v3.1',
    ]);

    $normalized = $provider->exposedNormalizeBill([
        'id'                  => 321,
        'bill_date'           => '2026-07-10',
        'vehicle_id'          => ' vehicle-9 ',
        'vehicle_card_id'     => ' card-9 ',
        'internal_number'     => ' internal-9 ',
        'structure_number'    => ' structure-9 ',
        'plate_snum'          => ' ABC  123 ',
        'vin'                 => ' VIN9 ',
        'serial_number'       => ' SER9 ',
        'call_sign'           => ' CALL9 ',
        'trip_number'         => ' TRIP9 ',
        'station_name'        => ' Station  1 ',
        'station_lat'         => 24.7,
        'station_lng'         => 46.7,
        'num_of_liters'       => 55.5,
        'cost'                => '120.25',
        'odometer'            => 123456,
        'payment_method'      => 'card',
        'payment_method_text' => 'Fleet card',
        'branch_name'         => 'Riyadh',
        'city'                => 'Riyadh',
        'district'            => 'North',
        'delegate_name'       => 'Operator',
    ]);

    expect($provider->key())->toBe('petroapp')
        ->and($provider->name())->toBe('PetroApp')
        ->and($provider->exposedBaseUrl($defaultConnection))->toBe('https://app-public.staging.petroapp.app/webservice')
        ->and($provider->exposedBaseUrl($customConnection))->toBe('https://petroapp.example.test/root')
        ->and($provider->exposedHeaders($defaultConnection))->toBe([
            'WS-Version'    => 'v2.0',
            'Authorization' => 'Bearer token-1',
        ])
        ->and($provider->exposedHeaders($customConnection))->toBe([
            'WS-Version' => 'v3.1',
            'WS-SK'      => 'key-1',
        ])
        ->and($normalized)->toMatchArray([
            'provider'                => 'petroapp',
            'provider_transaction_id' => '321',
            'provider_vehicle_id'     => 'vehicle-9',
            'vehicle_card_id'         => 'card-9',
            'internal_number'         => 'internal-9',
            'structure_number'        => 'structure-9',
            'plate_number'            => 'ABC 123',
            'vin'                     => 'VIN9',
            'serial_number'           => 'SER9',
            'call_sign'               => 'CALL9',
            'trip_number'             => 'TRIP9',
            'station_name'            => 'Station 1',
            'station_latitude'        => 24.7,
            'station_longitude'       => 46.7,
            'volume'                  => 55.5,
            'metric_unit'             => 'l',
            'amount'                  => 12025,
            'currency'                => 'SAR',
            'odometer'                => 123456,
        ])
        ->and($normalized['transaction_at']->toDateString())->toBe('2026-07-10')
        ->and($normalized['normalized_payload'])->toMatchArray([
            'payment_method'      => 'card',
            'payment_method_text' => 'Fleet card',
            'branch_name'         => 'Riyadh',
            'city'                => 'Riyadh',
            'district'            => 'North',
            'delegate_name'       => 'Operator',
        ]);
});
