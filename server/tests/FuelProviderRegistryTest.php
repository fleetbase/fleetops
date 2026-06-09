<?php

use Fleetbase\FleetOps\Contracts\FuelProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderDescriptor;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

test('native and extension fuel providers are exposed through the shared registry', function () {
    $registry = new FuelProviderRegistry();

    expect($registry->has('petroapp'))->toBeTrue();

    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'             => 'test_fuel_provider',
            'label'           => 'Test Fuel Provider',
            'type'            => 'extension',
            'driver_class'    => TestFuelProvider::class,
            'description'     => 'Test extension fuel provider.',
            'required_fields' => [
                [
                    'name'     => 'api_key',
                    'label'    => 'API Key',
                    'type'     => 'password',
                    'required' => true,
                ],
            ],
            'capabilities' => ['vehicles', 'transactions', 'stations'],
        ]));
    }

    $providers = (new FuelProviderService($registry))->providers();

    expect($registry->has('test_fuel_provider'))->toBeTrue()
        ->and($registry->resolve('test_fuel_provider'))->toBeInstanceOf(TestFuelProvider::class)
        ->and($providers->pluck('key')->all())->toContain('petroapp', 'test_fuel_provider')
        ->and($providers->firstWhere('key', 'test_fuel_provider')['type'])->toBe('extension')
        ->and($providers->firstWhere('key', 'test_fuel_provider')['capabilities'])->toBe(['vehicles', 'transactions', 'stations']);
});

class TestFuelProvider implements FuelProvider
{
    public function key(): string
    {
        return 'test_fuel_provider';
    }

    public function name(): string
    {
        return 'Test Fuel Provider';
    }

    public function authenticate(FuelProviderConnection $connection): array
    {
        return ['success' => true];
    }

    public function testConnection(FuelProviderConnection $connection): array
    {
        return ['success' => true, 'message' => 'Connected.', 'metadata' => []];
    }

    public function listVehicles(FuelProviderConnection $connection, array $options = []): Collection
    {
        return collect();
    }

    public function listTransactions(FuelProviderConnection $connection, Carbon $from, Carbon $to, array $options = []): Collection
    {
        return collect();
    }

    public function listStations(FuelProviderConnection $connection, array $options = []): Collection
    {
        return collect();
    }

    public function pushTrip(FuelProviderConnection $connection, array $trip): array
    {
        return [];
    }

    public function syncVehicle(FuelProviderConnection $connection, array $vehicle): array
    {
        return [];
    }

    public function webhookPayloadToTransaction(FuelProviderConnection $connection, array $payload): ?array
    {
        return null;
    }
}
