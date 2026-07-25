<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelTransactionController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Illuminate\Http\Request;

class FleetOpsWorkOrderControllerProbe extends WorkOrderController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(WorkOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsFuelTransactionControllerProbe extends FuelTransactionController
{
    public function __construct()
    {
        parent::__construct(new FuelProviderService(new FuelProviderRegistry()));
    }

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(FuelTransactionController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

test('work order controller input whitelists fields and clears blank morph relations', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();
    $request    = new Request([
        'code'            => 'WO-1',
        'subject'         => 'Replace tires',
        'category'        => 'maintenance',
        'status'          => 'open',
        'priority'        => 'high',
        'opened_at'       => '2026-01-01',
        'due_at'          => '2026-01-10',
        'closed_at'       => null,
        'instructions'    => 'Inspect all tires.',
        'checklist'       => [['label' => 'Tires']],
        'estimated_cost'  => 100,
        'approved_budget' => 150,
        'actual_cost'     => 90,
        'currency'        => 'USD',
        'cost_breakdown'  => ['labor' => 50],
        'cost_center'     => 'ops',
        'budget_code'     => 'BUD-1',
        'meta'            => ['source' => 'api'],
        'target'          => '',
        'target_type'     => 'vehicle',
        'assignee'        => null,
        'assignee_type'   => 'vendor',
        'company_uuid'    => 'spoofed-company',
        'uuid'            => 'spoofed-uuid',
    ]);

    expect($controller->callHelper('input', $request))->toBe([
        'code'            => 'WO-1',
        'subject'         => 'Replace tires',
        'category'        => 'maintenance',
        'status'          => 'open',
        'priority'        => 'high',
        'opened_at'       => '2026-01-01',
        'due_at'          => '2026-01-10',
        'closed_at'       => null,
        'instructions'    => 'Inspect all tires.',
        'checklist'       => [['label' => 'Tires']],
        'estimated_cost'  => 100,
        'approved_budget' => 150,
        'actual_cost'     => 90,
        'currency'        => 'USD',
        'cost_breakdown'  => ['labor' => 50],
        'cost_center'     => 'ops',
        'budget_code'     => 'BUD-1',
        'meta'            => ['source' => 'api'],
        'target_type'     => null,
        'target_uuid'     => null,
        'assignee_type'   => null,
        'assignee_uuid'   => null,
    ]);
});

test('fuel transaction controller input whitelists fields and clears blank public-id relations', function () {
    $controller = new FleetOpsFuelTransactionControllerProbe();
    $request    = new Request([
        'provider'                => 'fuelman',
        'provider_transaction_id' => 'txn-1',
        'provider_vehicle_id'     => 'vehicle-provider-1',
        'vehicle_card_id'         => 'card-1',
        'internal_number'         => 'internal-1',
        'structure_number'        => 'structure-1',
        'plate_number'            => 'SG-1234',
        'vin'                     => 'VIN123',
        'serial_number'           => 'SER123',
        'call_sign'               => 'CALL123',
        'trip_number'             => 'TRIP123',
        'station_name'            => 'Fuel Station',
        'station_latitude'        => 1.3,
        'station_longitude'       => 103.8,
        'transaction_at'          => '2026-01-01T10:00:00Z',
        'volume'                  => 42,
        'metric_unit'             => 'L',
        'amount'                  => 123.45,
        'currency'                => 'SGD',
        'odometer'                => 1000,
        'sync_status'             => 'pending',
        'matched_at'              => null,
        'normalized_payload'      => ['amount' => 123.45],
        'raw_payload'             => ['raw' => true],
        'meta'                    => ['source' => 'manual'],
        'connection'              => '',
        'fuel_report'             => null,
        'vehicle'                 => '',
        'driver'                  => null,
        'order'                   => '',
        'company_uuid'            => 'spoofed-company',
    ]);

    expect($controller->callHelper('input', $request))->toMatchArray([
        'provider'                      => 'fuelman',
        'provider_transaction_id'       => 'txn-1',
        'provider_vehicle_id'           => 'vehicle-provider-1',
        'vehicle_card_id'               => 'card-1',
        'internal_number'               => 'internal-1',
        'structure_number'              => 'structure-1',
        'plate_number'                  => 'SG-1234',
        'vin'                           => 'VIN123',
        'serial_number'                 => 'SER123',
        'call_sign'                     => 'CALL123',
        'trip_number'                   => 'TRIP123',
        'station_name'                  => 'Fuel Station',
        'station_latitude'              => 1.3,
        'station_longitude'             => 103.8,
        'transaction_at'                => '2026-01-01T10:00:00Z',
        'volume'                        => 42,
        'metric_unit'                   => 'L',
        'amount'                        => 123.45,
        'currency'                      => 'SGD',
        'odometer'                      => 1000,
        'sync_status'                   => 'pending',
        'matched_at'                    => null,
        'normalized_payload'            => ['amount' => 123.45],
        'raw_payload'                   => ['raw' => true],
        'meta'                          => ['source' => 'manual'],
        'fuel_provider_connection_uuid' => null,
        'fuel_report_uuid'              => null,
        'vehicle_uuid'                  => null,
        'driver_uuid'                   => null,
        'order_uuid'                    => null,
    ]);
});

test('public api resource resolver rejects uuid identifier keys recursively', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();

    expect($controller->callHelper('collectUuidIdentifierKeys', [
        'uuid'   => 'root',
        'target' => [
            'vehicle_uuid' => 'vehicle',
            'nested'       => ['driverUUID' => 'driver'],
        ],
    ]))->toBe(['uuid', 'target.vehicle_uuid', 'target.nested.driverUUID'])
        ->and($controller->callHelper('isUuidIdentifierKey', 'public_id'))->toBeFalse();
});
