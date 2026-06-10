<?php

use Fleetbase\FleetOps\Contracts\FuelProvider;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\Vehicle;
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
            'category'        => 'Fuel card integration',
            'icon'            => 'gas-pump',
            'required_fields' => [
                [
                    'name'     => 'api_key',
                    'label'    => 'API Key',
                    'type'     => 'password',
                    'required' => true,
                ],
            ],
            'capabilities' => ['vehicles', 'transactions', 'stations'],
            'sync_defaults' => [
                'window_days' => 7,
                'matching_order' => ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'],
                'auto_create_fuel_reports' => true,
            ],
        ]));
    }

    $providers = (new FuelProviderService($registry))->providers();

    expect($registry->has('test_fuel_provider'))->toBeTrue()
        ->and($registry->resolve('test_fuel_provider'))->toBeInstanceOf(TestFuelProvider::class)
        ->and($providers->pluck('key')->all())->toContain('petroapp', 'test_fuel_provider')
        ->and($providers->firstWhere('key', 'test_fuel_provider')['type'])->toBe('extension')
        ->and($providers->firstWhere('key', 'test_fuel_provider')['capabilities'])->toBe(['vehicles', 'transactions', 'stations'])
        ->and($providers->firstWhere('key', 'test_fuel_provider')['category'])->toBe('Fuel card integration')
        ->and($providers->firstWhere('key', 'test_fuel_provider')['sync_defaults']['window_days'])->toBe(7);
});

test('fuel provider service records sync runs and review states', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
    ]);

    $syncRun = $service->createSyncRun($connection, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

    expect($syncRun->provider)->toBe('test_fuel_provider')
        ->and($syncRun->status)->toBe('queued');

    $transaction = FuelProviderTransaction::create([
        'company_uuid' => session('company'),
        'fuel_provider_connection_uuid' => $connection->uuid,
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-1',
        'sync_status' => 'unmatched',
    ]);

    $reviewed = $service->reviewTransaction($transaction, 'reviewed');

    expect($reviewed->sync_status)->toBe('reviewed')
        ->and($reviewed->meta['review_status'])->toBe('reviewed');
});

test('fuel provider matching defaults to plate before provider or internal identifiers', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $internalMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_internal_default_priority',
        'internal_id'   => 'MATCH-001',
        'plate_number'  => 'INTERNAL-ONLY',
    ]);
    $plateMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_plate_default_priority',
        'internal_id'   => 'PLATE-ONLY',
        'plate_number'  => 'MATCH-001',
    ]);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
        'sync_settings' => [
            'auto_create_fuel_reports' => false,
        ],
    ]);

    $transaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-default-plate-first',
        'provider_vehicle_id' => $internalMatch->public_id,
        'internal_number' => 'MATCH-001',
        'plate_number' => 'MATCH-001',
    ]);

    expect($transaction->vehicle_uuid)->toBe($plateMatch->uuid);
});

test('fuel provider matching upgrades old provider-first defaults to production defaults', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $internalMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_internal_legacy_priority',
        'internal_id'   => 'MATCH-LEGACY',
        'plate_number'  => 'INTERNAL-LEGACY',
    ]);
    $plateMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_plate_legacy_priority',
        'internal_id'   => 'PLATE-LEGACY',
        'plate_number'  => 'MATCH-LEGACY',
    ]);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
        'sync_settings' => [
            'matching_order' => ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number'],
            'auto_create_fuel_reports' => false,
        ],
    ]);

    $transaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-legacy-default-upgrade',
        'provider_vehicle_id' => $internalMatch->public_id,
        'internal_number' => 'MATCH-LEGACY',
        'plate_number' => 'MATCH-LEGACY',
    ]);

    expect($transaction->vehicle_uuid)->toBe($plateMatch->uuid);
});

test('fuel provider matching honors configured identifier priority', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $internalMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_internal_configured_priority',
        'internal_id'   => 'MATCH-002',
        'plate_number'  => 'INTERNAL-ONLY-2',
    ]);
    $plateMatch = Vehicle::create([
        'company_uuid'  => session('company'),
        'public_id'     => 'vehicle_plate_configured_priority',
        'internal_id'   => 'PLATE-ONLY-2',
        'plate_number'  => 'MATCH-002',
    ]);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
        'sync_settings' => [
            'matching_order' => ['internal_id', 'plate_number'],
            'auto_create_fuel_reports' => false,
        ],
    ]);

    $internalFirst = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-internal-first',
        'internal_number' => 'MATCH-002',
        'plate_number' => 'MATCH-002',
    ]);

    $connection->sync_settings = [
        'matching_order' => ['plate_number', 'internal_id'],
        'auto_create_fuel_reports' => false,
    ];
    $connection->save();

    $plateFirst = $service->ingestTransaction($connection->fresh(), [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-plate-first',
        'internal_number' => 'MATCH-002',
        'plate_number' => 'MATCH-002',
    ]);

    expect($internalFirst->vehicle_uuid)->toBe($internalMatch->uuid)
        ->and($plateFirst->vehicle_uuid)->toBe($plateMatch->uuid);
});

test('fuel provider matching resolves production vehicle identity fields', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $vinMatch = Vehicle::create([
        'company_uuid' => session('company'),
        'public_id' => 'vehicle_vin_priority',
        'vin' => 'VIN-001',
    ]);
    $serialMatch = Vehicle::create([
        'company_uuid' => session('company'),
        'public_id' => 'vehicle_serial_priority',
        'serial_number' => 'SERIAL-001',
    ]);
    $callSignMatch = Vehicle::create([
        'company_uuid' => session('company'),
        'public_id' => 'vehicle_call_sign_priority',
        'call_sign' => 'CALL-001',
    ]);
    $fuelCardMatch = Vehicle::create([
        'company_uuid' => session('company'),
        'public_id' => 'vehicle_fuel_card_priority',
        'fuel_card_number' => 'CARD-001',
    ]);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
        'sync_settings' => [
            'matching_order' => ['vin', 'serial_number', 'call_sign', 'fuel_card_number'],
            'auto_create_fuel_reports' => false,
        ],
    ]);

    $vinTransaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-vin',
        'vin' => 'VIN 001',
    ]);
    $serialTransaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-serial',
        'serial_number' => 'SERIAL 001',
    ]);
    $callSignTransaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-call-sign',
        'call_sign' => 'CALL 001',
    ]);
    $fuelCardTransaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-fuel-card',
        'vehicle_card_id' => 'CARD 001',
    ]);

    expect($vinTransaction->vehicle_uuid)->toBe($vinMatch->uuid)
        ->and($serialTransaction->vehicle_uuid)->toBe($serialMatch->uuid)
        ->and($callSignTransaction->vehicle_uuid)->toBe($callSignMatch->uuid)
        ->and($fuelCardTransaction->vehicle_uuid)->toBe($fuelCardMatch->uuid);
});

test('fuel provider matching leaves transactions unmatched when no selected identifier resolves', function () {
    session(['company' => '00000000-0000-0000-0000-000000000001']);

    $registry = new FuelProviderRegistry();
    if (!$registry->has('test_fuel_provider')) {
        $registry->register(new FuelProviderDescriptor([
            'key'          => 'test_fuel_provider',
            'label'        => 'Test Fuel Provider',
            'type'         => 'extension',
            'driver_class' => TestFuelProvider::class,
        ]));
    }

    $service = new FuelProviderService($registry);
    $connection = FuelProviderConnection::create([
        'company_uuid' => session('company'),
        'provider' => 'test_fuel_provider',
        'name' => 'Test Fuel',
        'environment' => 'production',
        'status' => 'configured',
        'sync_settings' => [
            'matching_order' => ['plate_number', 'internal_id'],
            'auto_create_fuel_reports' => false,
        ],
    ]);

    $transaction = $service->ingestTransaction($connection, [
        'provider' => 'test_fuel_provider',
        'provider_transaction_id' => 'txn-unmatched',
        'plate_number' => 'NO-MATCH',
        'internal_number' => 'NO-MATCH',
    ]);

    expect($transaction->vehicle_uuid)->toBeNull()
        ->and($transaction->sync_status)->toBe('unmatched');
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
