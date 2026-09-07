<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelTransactionController;
use Fleetbase\FleetOps\Http\Requests\CreateFuelTransactionRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFuelTransactionRequest;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetOpsApiFuelTransactionControllerProbe extends FuelTransactionController
{
    public ?FleetOpsApiFuelTransactionFake $createdTransaction = null;
    public array $creates                                      = [];
    public array $models                                       = [];
    public array $resolvedUuids                                = [];
    public array $resources                                    = [];
    public array $deletedResources                             = [];
    public array $collections                                  = [];
    public array $queries                                      = [];

    protected function createTransaction(array $input): FuelProviderTransaction
    {
        $this->creates[] = $input;

        return $this->createdTransaction;
    }

    protected function queryTransactionsWithRequest(Request $request, callable $callback): mixed
    {
        $query = new FleetOpsApiFuelTransactionQueryFake();
        $callback($query);
        $this->queries[] = $query->calls;

        return [
            ['uuid' => 'transaction-a'],
            ['uuid' => 'transaction-b'],
        ];
    }

    protected function resolveUuid(string $modelClass, ?string $id, ?string $companyUuid = null): ?string
    {
        $this->resolvedUuids[] = [$modelClass, $id];

        return filled($id) ? $id . '-uuid' : null;
    }

    protected function resolveModel(string $modelClass, string $id, ?string $companyUuid = null): EloquentModel
    {
        $key = $modelClass . ':' . $id;

        if (!array_key_exists($key, $this->models)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return $this->models[$key];
    }

    protected function fuelTransactionResource(FuelProviderTransaction $transaction): mixed
    {
        $this->resources[] = $transaction->uuid;

        return [
            'uuid'        => $transaction->uuid,
            'public_id'   => $transaction->public_id,
            'sync_status' => $transaction->sync_status,
        ];
    }

    protected function fuelTransactionResourceCollection($transactions): mixed
    {
        $this->collections[] = $transactions;

        return ['collection' => $transactions];
    }

    protected function deletedFuelTransactionResource(FuelProviderTransaction $transaction): mixed
    {
        $this->deletedResources[] = $transaction->uuid;

        return ['deleted' => $transaction->uuid];
    }
}

class FleetOpsApiFuelTransactionServiceFake extends FuelProviderService
{
    public array $calls = [];

    public function __construct()
    {
    }

    public function matchVehicle(FuelProviderTransaction $transaction, Vehicle|string $vehicle): FuelProviderTransaction
    {
        $this->calls[] = ['matchVehicle', $transaction->uuid, $vehicle instanceof Vehicle ? $vehicle->uuid : $vehicle];
        $transaction->setAttribute('sync_status', 'vehicle_matched');

        return $transaction;
    }

    public function matchOrder(FuelProviderTransaction $transaction, Order|string $order): FuelProviderTransaction
    {
        $this->calls[] = ['matchOrder', $transaction->uuid, $order instanceof Order ? $order->uuid : $order];
        $transaction->setAttribute('sync_status', 'order_matched');

        return $transaction;
    }

    public function reprocessTransaction(FuelProviderTransaction $transaction): FuelProviderTransaction
    {
        $this->calls[] = ['reprocessTransaction', $transaction->uuid];
        $transaction->setAttribute('sync_status', 'reprocessed');

        return $transaction;
    }

    public function reviewTransaction(FuelProviderTransaction $transaction, string $status): FuelProviderTransaction
    {
        $this->calls[] = ['reviewTransaction', $transaction->uuid, $status];
        $transaction->setAttribute('sync_status', $status);

        return $transaction;
    }
}

class FleetOpsApiFuelTransactionFake extends FuelProviderTransaction
{
    public array $loads    = [];
    public array $updates  = [];
    public bool $deleted   = false;
    public bool $refreshed = false;

    public function load($relations)
    {
        $this->loads[] = $relations;

        return $this;
    }

    public function refresh()
    {
        $this->refreshed = true;

        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes));

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }
}

class FleetOpsApiFuelTransactionQueryFake
{
    public array $calls = [];

    public function with(array $relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }
}

function fleetopsApiFuelTransactionController(?FleetOpsApiFuelTransactionServiceFake $service = null): FleetOpsApiFuelTransactionControllerProbe
{
    return new FleetOpsApiFuelTransactionControllerProbe($service ?? new FleetOpsApiFuelTransactionServiceFake());
}

function fleetopsApiFuelTransactionFake(string $uuid = 'transaction-uuid', string $publicId = 'fuel_transaction_public'): FleetOpsApiFuelTransactionFake
{
    $transaction = new FleetOpsApiFuelTransactionFake();
    $transaction->setRawAttributes([
        'uuid'        => $uuid,
        'public_id'   => $publicId,
        'sync_status' => 'pending',
    ]);

    return $transaction;
}

function fleetopsApiFuelTransactionRelated(string $modelClass, string $uuid): EloquentModel
{
    $model = new $modelClass();
    $model->setRawAttributes(['uuid' => $uuid, 'public_id' => $uuid . '_public']);

    return $model;
}

function fleetopsApiFuelTransactionRequest(string $class, array $input): Request
{
    return $class::create('/api/v1/fuel-transactions', 'POST', $input);
}

test('api fuel transaction controller creates transactions with public relations', function () {
    session(['company' => 'company-uuid']);

    $controller                     = fleetopsApiFuelTransactionController();
    $controller->createdTransaction = fleetopsApiFuelTransactionFake();

    $response = $controller->create(fleetopsApiFuelTransactionRequest(CreateFuelTransactionRequest::class, [
        'provider'                 => 'test-provider',
        'provider_transaction_id'  => 'provider-tx-1',
        'provider_vehicle_id'      => 'provider-vehicle-1',
        'station_name'             => 'Downtown Fuel',
        'volume'                   => 42,
        'metric_unit'              => 'gal',
        'amount'                   => 123.45,
        'currency'                 => 'USD',
        'connection'               => 'connection_public',
        'fuel_report'              => 'fuel_report_public',
        'vehicle'                  => 'vehicle_public',
        'driver'                   => '',
        'order'                    => 'order_public',
        'ignored'                  => 'not copied',
    ]));

    expect($response['uuid'])->toBe('transaction-uuid')
        ->and($controller->creates)->toHaveCount(1)
        ->and($controller->creates[0])->toMatchArray([
            'company_uuid'                  => 'company-uuid',
            'provider'                      => 'test-provider',
            'provider_transaction_id'       => 'provider-tx-1',
            'provider_vehicle_id'           => 'provider-vehicle-1',
            'station_name'                  => 'Downtown Fuel',
            'volume'                        => 42,
            'metric_unit'                   => 'gal',
            'amount'                        => 123.45,
            'currency'                      => 'USD',
            'fuel_provider_connection_uuid' => 'connection_public-uuid',
            'fuel_report_uuid'              => 'fuel_report_public-uuid',
            'vehicle_uuid'                  => 'vehicle_public-uuid',
            'driver_uuid'                   => null,
            'order_uuid'                    => 'order_public-uuid',
        ])
        ->and($controller->resolvedUuids)->toBe([
            [FuelProviderConnection::class, 'connection_public'],
            [FuelReport::class, 'fuel_report_public'],
            [Vehicle::class, 'vehicle_public'],
            [Order::class, 'order_public'],
        ])
        ->and($controller->createdTransaction->loads)->toBe([
            ['connection', 'vehicle', 'driver', 'order', 'fuelReport'],
        ]);
});

test('api fuel transaction controller rejects uuid relation identifiers', function () {
    $controller = fleetopsApiFuelTransactionController();

    expect(fn () => $controller->create(fleetopsApiFuelTransactionRequest(CreateFuelTransactionRequest::class, [
        'vehicle_uuid' => 'vehicle-uuid',
    ])))->toThrow(ValidationException::class);
});

test('api fuel transaction controller updates finds deletes and queries transactions', function () {
    $controller                                                          = fleetopsApiFuelTransactionController();
    $transaction                                                         = fleetopsApiFuelTransactionFake();
    $controller->models[FuelProviderTransaction::class . ':transaction'] = $transaction;

    $updated = $controller->update('transaction', fleetopsApiFuelTransactionRequest(UpdateFuelTransactionRequest::class, [
        'station_name' => 'Updated station',
        'sync_status'  => 'matched',
        'vehicle'      => '',
    ]));

    expect($updated['uuid'])->toBe('transaction-uuid')
        ->and($transaction->updates)->toBe([[
            'station_name' => 'Updated station',
            'sync_status'  => 'matched',
            'vehicle_uuid' => null,
        ]])
        ->and($transaction->refreshed)->toBeTrue()
        ->and($transaction->loads)->toContain(['connection', 'vehicle', 'driver', 'order', 'fuelReport'])
        ->and($controller->find('transaction')['uuid'])->toBe('transaction-uuid')
        ->and($controller->delete('transaction'))->toBe(['deleted' => 'transaction-uuid'])
        ->and($transaction->deleted)->toBeTrue()
        ->and($controller->query(Request::create('/api/v1/fuel-transactions', 'GET')))->toBe([
            'collection' => [
                ['uuid' => 'transaction-a'],
                ['uuid' => 'transaction-b'],
            ],
        ])
        ->and($controller->queries)->toBe([
            [['with', ['connection', 'vehicle', 'driver', 'order', 'fuelReport']]],
        ]);
});

test('api fuel transaction controller reports missing transactions', function () {
    $controller = fleetopsApiFuelTransactionController();

    expect($controller->find('missing')->getStatusCode())->toBe(404)
        ->and($controller->update('missing', fleetopsApiFuelTransactionRequest(UpdateFuelTransactionRequest::class, [
            'sync_status' => 'matched',
        ]))->getData(true))->toBe(['error' => 'FuelTransaction resource not found.'])
        ->and($controller->delete('missing')->getStatusCode())->toBe(404);
});

test('api fuel transaction controller delegates matching review and reprocessing', function () {
    $service                                                             = new FleetOpsApiFuelTransactionServiceFake();
    $controller                                                          = fleetopsApiFuelTransactionController($service);
    $transaction                                                         = fleetopsApiFuelTransactionFake();
    $vehicle                                                             = fleetopsApiFuelTransactionRelated(Vehicle::class, 'vehicle-uuid');
    $order                                                               = fleetopsApiFuelTransactionRelated(Order::class, 'order-uuid');
    $controller->models[FuelProviderTransaction::class . ':transaction'] = $transaction;
    $controller->models[Vehicle::class . ':vehicle_public']              = $vehicle;
    $controller->models[Order::class . ':order_public']                  = $order;

    $vehicleData = $controller->matchVehicle(Request::create('/api/v1/fuel-transactions/transaction/match-vehicle', 'POST', [
        'vehicle' => 'vehicle_public',
    ]), 'transaction')->getData(true);

    $orderData = $controller->matchOrder(Request::create('/api/v1/fuel-transactions/transaction/match-order', 'POST', [
        'order' => 'order_public',
    ]), 'transaction')->getData(true);

    $reprocessData = $controller->reprocess('transaction')->getData(true);

    $reviewData = $controller->review(Request::create('/api/v1/fuel-transactions/transaction/review', 'POST', [
        'status' => 'ignored',
    ]), 'transaction')->getData(true);

    expect($vehicleData['status'])->toBe('ok')
        ->and($vehicleData['transaction']['sync_status'])->toBe('vehicle_matched')
        ->and($orderData['status'])->toBe('ok')
        ->and($orderData['transaction']['sync_status'])->toBe('order_matched')
        ->and($reprocessData['status'])->toBe('ok')
        ->and($reprocessData['transaction']['sync_status'])->toBe('reprocessed')
        ->and($reviewData['status'])->toBe('ok')
        ->and($reviewData['transaction']['sync_status'])->toBe('ignored')
        ->and($service->calls)->toBe([
            ['matchVehicle', 'transaction-uuid', 'vehicle-uuid'],
            ['matchOrder', 'transaction-uuid', 'order-uuid'],
            ['reprocessTransaction', 'transaction-uuid'],
            ['reviewTransaction', 'transaction-uuid', 'ignored'],
        ]);
});
