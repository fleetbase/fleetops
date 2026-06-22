<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DeviceController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\EquipmentController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\FuelTransactionController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\PartController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\SensorController;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\WorkOrderController;
use Fleetbase\FleetOps\Http\Resources\v1\Device;
use Fleetbase\FleetOps\Http\Resources\v1\Equipment;
use Fleetbase\FleetOps\Http\Resources\v1\FuelTransaction;
use Fleetbase\FleetOps\Http\Resources\v1\Part;
use Fleetbase\FleetOps\Http\Resources\v1\Sensor;
use Fleetbase\FleetOps\Http\Resources\v1\WorkOrder;

test('new first-class fleetops rest routes are registered in the consumable v1 namespace', function () {
    $routes = file_get_contents(dirname(__DIR__) . '/src/routes.php');

    foreach (['equipment', 'parts', 'work-orders', 'devices', 'sensors', 'fuel-transactions'] as $prefix) {
        expect($routes)->toContain("\$router->group(['prefix' => '{$prefix}']");
    }

    expect($routes)
        ->toContain('EquipmentController@create')
        ->toContain('PartController@create')
        ->toContain('WorkOrderController@send')
        ->toContain('DeviceController@attach')
        ->toContain('DeviceController@detach')
        ->toContain('SensorController@create')
        ->toContain('FuelTransactionController@matchVehicle')
        ->toContain('FuelTransactionController@matchOrder')
        ->toContain('FuelTransactionController@reprocess')
        ->toContain('FuelTransactionController@review');
});

test('public fuel transaction api keeps provider naming internal only', function () {
    $publicRoutes = file_get_contents(dirname(__DIR__) . '/src/routes.php');
    $publicGroup  = substr($publicRoutes, 0, strpos($publicRoutes, 'Internal FleetOps API Routes'));
    $controller   = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/FuelTransactionController.php');

    expect($publicGroup)
        ->toContain("['prefix' => 'fuel-transactions']")
        ->not->toContain("['prefix' => 'fuel-provider-transactions']")
        ->and($controller)
        ->toContain('class FuelTransactionController')
        ->toContain('FuelProviderTransaction')
        ->toContain("'FuelTransaction resource not found.'");
});

test('new public controllers expose the expected rest methods', function () {
    $controllers = [
        EquipmentController::class       => ['create', 'query', 'find', 'update', 'delete'],
        PartController::class            => ['create', 'query', 'find', 'update', 'delete'],
        WorkOrderController::class       => ['create', 'query', 'find', 'update', 'delete', 'send'],
        DeviceController::class          => ['create', 'query', 'find', 'update', 'delete', 'attach', 'detach'],
        SensorController::class          => ['create', 'query', 'find', 'update', 'delete'],
        FuelTransactionController::class => ['create', 'query', 'find', 'update', 'delete', 'matchVehicle', 'matchOrder', 'reprocess', 'review'],
    ];

    foreach ($controllers as $controller => $methods) {
        foreach ($methods as $method) {
            expect(method_exists($controller, $method))->toBeTrue("{$controller}::{$method} missing");
        }
    }
});

test('new public resources exist and do not expose uuid identifier fields', function () {
    foreach ([Equipment::class, Part::class, WorkOrder::class, Device::class, Sensor::class, FuelTransaction::class] as $resource) {
        expect(class_exists($resource))->toBeTrue();
    }

    foreach ([
        'Equipment.php',
        'Part.php',
        'Device.php',
        'Sensor.php',
        'FuelTransaction.php',
        'WorkOrder.php',
    ] as $resourceFile) {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/' . $resourceFile);

        expect($source)
            ->toContain("'id'")
            ->not->toMatch("/'[^']*uuid'/i");
    }
});

test('new public resolver rejects uuid lookup while preserving public and internal identifiers', function () {
    $resolver = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/Concerns/ResolvesFleetOpsApiResources.php');

    expect($resolver)
        ->not->toContain("where('uuid', \$id)")
        ->not->toContain("orWhere('uuid', \$id)")
        ->toContain("orWhere('public_id', \$id)")
        ->toContain("orWhere('internal_id', \$id)")
        ->toContain('rejectUuidIdentifiers')
        ->toContain('Use public_id or internal_id values instead');
});

test('public controllers reject uuid shaped request keys', function () {
    foreach ([
        'EquipmentController.php',
        'PartController.php',
        'WorkOrderController.php',
        'DeviceController.php',
        'SensorController.php',
        'FuelTransactionController.php',
    ] as $controllerFile) {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/Api/v1/' . $controllerFile);

        expect($source)->toContain('rejectUuidIdentifiers($request)');
    }
});

test('new public list endpoints have company scoped filters', function () {
    foreach ([
        'EquipmentFilter.php',
        'PartFilter.php',
        'WorkOrderFilter.php',
        'DeviceFilter.php',
        'SensorFilter.php',
        'FuelProviderTransactionFilter.php',
    ] as $filterFile) {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/' . $filterFile);

        expect($source)
            ->toContain('public function queryForPublic()')
            ->toContain("where('company_uuid', \$this->session->get('company'))");
    }
});

test('fuel transaction filter applies public company scoping and public id relation filters without raw uuid matching', function () {
    $filter = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/FuelProviderTransactionFilter.php');

    expect($filter)
        ->toContain('public function queryForPublic()')
        ->toContain('$this->queryForInternal();')
        ->toContain('public function vehicle(?string $vehicle)')
        ->toContain('public function driver(?string $driver)')
        ->toContain('public function order(?string $order)')
        ->toContain('public function fuelReport(?string $fuelReport)')
        ->toContain("where('public_id', \$identifier)")
        ->toContain("orWhere('internal_id', \$identifier)")
        ->toContain("if (\$allowUuid)")
        ->not->toContain("where('vehicle_uuid', \$vehicle)")
        ->not->toContain("where('driver_uuid', \$driver)")
        ->not->toContain("where('order_uuid', \$order)")
        ->not->toContain("where('fuel_report_uuid', \$fuelReport)");
});
