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

test('new public resources exist and hide internal identifiers behind internal request checks', function () {
    foreach ([Equipment::class, Part::class, WorkOrder::class, Device::class, Sensor::class, FuelTransaction::class] as $resource) {
        expect(class_exists($resource))->toBeTrue();
    }

    foreach ([
        'Equipment.php',
        'Part.php',
        'Device.php',
        'Sensor.php',
        'FuelTransaction.php',
    ] as $resourceFile) {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/Resources/v1/' . $resourceFile);

        expect($source)
            ->toContain('Http::isInternalRequest()')
            ->toContain("'id'")
            ->toContain("'uuid'");
    }
});

test('fuel transaction filter applies public company scoping and public id relation filters', function () {
    $filter = file_get_contents(dirname(__DIR__) . '/src/Http/Filter/FuelProviderTransactionFilter.php');

    expect($filter)
        ->toContain('public function queryForPublic()')
        ->toContain('$this->queryForInternal();')
        ->toContain('public function vehicle(?string $vehicle)')
        ->toContain("where('public_id', \$vehicle)")
        ->toContain('public function driver(?string $driver)')
        ->toContain("where('public_id', \$driver)")
        ->toContain('public function order(?string $order)')
        ->toContain("where('public_id', \$order)")
        ->toContain('public function fuelReport(?string $fuelReport)')
        ->toContain("where('public_id', \$fuelReport)");
});
