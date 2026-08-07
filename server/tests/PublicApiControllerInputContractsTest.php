<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelTransactionController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Part;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderRegistry;
use Fleetbase\FleetOps\Support\FuelProviders\FuelProviderService;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetOpsWorkOrderControllerProbe extends WorkOrderController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(WorkOrderController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    public function applyRelation(array &$input, string $requestKey, string $column, string $modelClass, Request $request): void
    {
        $this->applyPublicIdRelation($input, $requestKey, $column, $modelClass, $request);
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

class FleetOpsPublicApiResolverModelFake extends Model
{
    protected $fillable = ['public_id', 'company_uuid'];

    public function getKeyName()
    {
        return 'uuid';
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

test('public api resource resolver rejects uuid identifiers from query and body payloads', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();
    $request    = Request::create('/api/v1/work-orders', 'POST', [
        'target_uuid' => 'target-uuid',
        'meta'        => ['driverUUID' => 'driver-uuid'],
    ], [], [], ['QUERY_STRING' => 'uuid=query-uuid']);
    $request->query->set('uuid', 'query-uuid');

    try {
        $controller->callHelper('rejectUuidIdentifiers', $request);

        $this->fail('Expected UUID identifier validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([
            'uuid'            => ['UUID identifiers are not accepted by the public API. Use public_id or internal_id values instead.'],
            'target_uuid'     => ['UUID identifiers are not accepted by the public API. Use public_id or internal_id values instead.'],
            'meta.driverUUID' => ['UUID identifiers are not accepted by the public API. Use public_id or internal_id values instead.'],
        ]);
    }
});

test('public api resource resolver handles blank identifiers and blank public id relations', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();
    $input      = ['subject' => 'Inspect vehicle'];

    $controller->applyRelation($input, 'vehicle', 'vehicle_uuid', Vehicle::class, new Request([]));

    expect($input)->toBe(['subject' => 'Inspect vehicle'])
        ->and($controller->callHelper('resolveUuid', Vehicle::class, null))->toBeNull()
        ->and($controller->callHelper('resolveUuid', Vehicle::class, ''))->toBeNull()
        ->and($controller->callHelper('resolveMorph', null, 'vehicle-1'))->toBe([null, null])
        ->and($controller->callHelper('resolveMorph', 'vehicle', null))->toBe([null, null]);

    $controller->applyRelation($input, 'vehicle', 'vehicle_uuid', Vehicle::class, new Request(['vehicle' => '']));

    expect($input['vehicle_uuid'])->toBeNull();
});

test('public api resource resolver exposes every supported morph alias', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();

    expect($controller->callHelper('allowedMorphTypes'))->toBe([
        'fleet-ops:vehicle'                  => Vehicle::class,
        'vehicle'                            => Vehicle::class,
        Vehicle::class                       => Vehicle::class,
        'fleet-ops:driver'                   => Driver::class,
        'driver'                             => Driver::class,
        Driver::class                        => Driver::class,
        'fleet-ops:equipment'                => Equipment::class,
        'equipment'                          => Equipment::class,
        Equipment::class                     => Equipment::class,
        'fleet-ops:part'                     => Part::class,
        'part'                               => Part::class,
        Part::class                          => Part::class,
        'fleet-ops:vendor'                   => Vendor::class,
        'vendor'                             => Vendor::class,
        Vendor::class                        => Vendor::class,
        'fleet-ops:contact'                  => Contact::class,
        'contact'                            => Contact::class,
        Contact::class                       => Contact::class,
        'fleet-ops:device'                   => Device::class,
        'device'                             => Device::class,
        Device::class                        => Device::class,
        'fleet-ops:telematic'                => Telematic::class,
        'telematic'                          => Telematic::class,
        Telematic::class                     => Telematic::class,
        'fleet-ops:warranty'                 => Warranty::class,
        'warranty'                           => Warranty::class,
        Warranty::class                      => Warranty::class,
        'fleet-ops:fuel-report'              => FuelReport::class,
        'fuel-report'                        => FuelReport::class,
        FuelReport::class                    => FuelReport::class,
        'fleet-ops:fuel-provider-connection' => FuelProviderConnection::class,
        'fuel-provider-connection'           => FuelProviderConnection::class,
        FuelProviderConnection::class        => FuelProviderConnection::class,
        'fleet-ops:order'                    => Order::class,
        'order'                              => Order::class,
        Order::class                         => Order::class,
        'file'                               => File::class,
        File::class                          => File::class,
    ]);
});

test('public api resource resolver detects fillable columns and primary keys', function () {
    $controller = new FleetOpsWorkOrderControllerProbe();
    $model      = new FleetOpsPublicApiResolverModelFake();

    expect($controller->callHelper('modelHasColumn', $model, 'company_uuid'))->toBeTrue()
        ->and($controller->callHelper('modelHasColumn', $model, 'uuid'))->toBeTrue()
        ->and($controller->callHelper('modelHasColumn', $model, 'internal_id'))->toBeFalse();
});
