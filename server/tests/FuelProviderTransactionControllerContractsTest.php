<?php

if (!function_exists('Fleetbase\Traits\config')) {
    eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
}

if (!function_exists('Fleetbase\Models\config')) {
    eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $key === "fleetbase.connection.db" ? "mysql" : $default; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelProviderTransactionController;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\Request;

class FleetOpsFuelProviderTransactionControllerProbe extends FuelProviderTransactionController
{
    public FuelProviderTransaction $transaction;
    public array $lookups = [];

    protected function findTransaction(string $id): FuelProviderTransaction
    {
        $this->lookups[] = $id;

        return $this->transaction;
    }
}

class FleetOpsFuelProviderTransactionServiceFake extends FuelProviderService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function matchVehicle(FuelProviderTransaction $transaction, Vehicle|string $vehicle): FuelProviderTransaction
    {
        $this->calls[] = ['matchVehicle', $transaction, $vehicle];
        $transaction->setAttribute('matched_vehicle', $vehicle);

        return $transaction;
    }

    public function matchOrder(FuelProviderTransaction $transaction, Order|string $order): FuelProviderTransaction
    {
        $this->calls[] = ['matchOrder', $transaction, $order];
        $transaction->setAttribute('matched_order', $order);

        return $transaction;
    }

    public function reprocessTransaction(FuelProviderTransaction $transaction): FuelProviderTransaction
    {
        $this->calls[] = ['reprocessTransaction', $transaction];
        $transaction->setAttribute('reprocessed', true);

        return $transaction;
    }

    public function reviewTransaction(FuelProviderTransaction $transaction, string $status): FuelProviderTransaction
    {
        $this->calls[] = ['reviewTransaction', $transaction, $status];
        $transaction->setAttribute('review_status', $status);

        return $transaction;
    }
}

class FleetOpsFuelProviderTransactionFake extends FuelProviderTransaction
{
    public function toArray(): array
    {
        return $this->getAttributes();
    }
}

class FleetOpsFuelProviderTransactionQueryFake
{
    public array $calls = [];

    public function with(array $relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }
}

function fleetopsFuelProviderTransactionController(FleetOpsFuelProviderTransactionServiceFake $service, FuelProviderTransaction $transaction): FleetOpsFuelProviderTransactionControllerProbe
{
    $controller              = new FleetOpsFuelProviderTransactionControllerProbe($service);
    $controller->transaction = $transaction;

    return $controller;
}

test('fuel provider transaction controller eager loads related records for queries', function () {
    $query = new FleetOpsFuelProviderTransactionQueryFake();

    FuelProviderTransactionController::onQueryRecord($query, new Request());

    expect($query->calls)->toBe([
        ['with', ['vehicle', 'driver', 'fuelReport']],
    ]);
});

test('fuel provider transaction controller delegates matching and review actions to service', function () {
    $service     = new FleetOpsFuelProviderTransactionServiceFake();
    $transaction = new FleetOpsFuelProviderTransactionFake();
    $transaction->setRawAttributes(['uuid' => 'transaction-uuid']);
    $controller  = fleetopsFuelProviderTransactionController($service, $transaction);

    $vehicleResponse = $controller->matchVehicle(Request::create('/transactions/tx/match-vehicle', 'POST', [
        'vehicle' => 'vehicle_public_id',
    ]), 'fuel_provider_transaction_123');

    $orderResponse = $controller->matchOrder(Request::create('/transactions/tx/match-order', 'POST', [
        'order' => 'order_public_id',
    ]), 'fuel_provider_transaction_123');

    $reprocessResponse = $controller->reprocess(new Request(), 'fuel_provider_transaction_123');

    $reviewResponse = $controller->review(Request::create('/transactions/tx/review', 'POST', [
        'status' => 'reviewed',
    ]), 'fuel_provider_transaction_123');

    $vehicleData   = $vehicleResponse->getData(true);
    $orderData     = $orderResponse->getData(true);
    $reprocessData = $reprocessResponse->getData(true);
    $reviewData    = $reviewResponse->getData(true);

    expect($vehicleData['status'])->toBe('ok')
        ->and($vehicleData['transaction']['uuid'])->toBe('transaction-uuid')
        ->and($vehicleData['transaction']['matched_vehicle'])->toBe('vehicle_public_id')
        ->and($orderData['status'])->toBe('ok')
        ->and($orderData['transaction']['matched_order'])->toBe('order_public_id')
        ->and($reprocessData['status'])->toBe('ok')
        ->and($reprocessData['transaction']['reprocessed'])->toBeTrue()
        ->and($reviewData['status'])->toBe('ok')
        ->and($reviewData['transaction']['review_status'])->toBe('reviewed')
        ->and($controller->lookups)->toBe([
            'fuel_provider_transaction_123',
            'fuel_provider_transaction_123',
            'fuel_provider_transaction_123',
            'fuel_provider_transaction_123',
        ])
        ->and($service->calls)->toBe([
            ['matchVehicle', $transaction, 'vehicle_public_id'],
            ['matchOrder', $transaction, 'order_public_id'],
            ['reprocessTransaction', $transaction],
            ['reviewTransaction', $transaction, 'reviewed'],
        ]);
});
