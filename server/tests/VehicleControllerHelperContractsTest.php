<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VehicleController;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\Request;

class FleetOpsVehicleControllerProbe extends VehicleController
{
    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(VehicleController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }
}

class FleetOpsVehicleActiveOrderFake extends Vehicle
{
    public array $loaded       = [];
    public mixed $lastPosition = null;

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function lastKnownPosition()
    {
        return $this->lastPosition;
    }
}

test('vehicle controller detects driver assignment payloads from nested and top level inputs', function (array $payload, bool $expected) {
    $controller = new FleetOpsVehicleControllerProbe();
    $request    = new Request($payload);

    expect($controller->callHelper('hasDriverInput', $request))->toBe($expected);
})->with([
    'vehicle driver uuid'        => [['vehicle' => ['driver_uuid' => 'driver-uuid']], true],
    'vehicle driver uuid object' => [['vehicle' => ['driver' => ['uuid' => 'driver-uuid']]], true],
    'vehicle driver id object'   => [['vehicle' => ['driver' => ['id' => 'driver-public']]], true],
    'top level driver uuid'      => [['driver_uuid' => 'driver-uuid'], true],
    'top level driver string'    => [['driver' => 'driver-public'], true],
    'missing driver input'       => [['vehicle' => ['plate_number' => 'SG-1234']], false],
]);

test('vehicle controller resolves driver identifiers using request priority order', function (array $payload, ?string $expected) {
    $controller = new FleetOpsVehicleControllerProbe();
    $request    = new Request($payload);

    expect($controller->callHelper('driverIdentifierFromRequest', $request))->toBe($expected);
})->with([
    'vehicle driver uuid wins' => [[
        'vehicle' => [
            'driver_uuid' => 'driver-uuid',
            'driver'      => ['uuid' => 'nested-uuid', 'id' => 'nested-id'],
        ],
        'driver_uuid' => 'top-level-driver-uuid',
    ], 'driver-uuid'],
    'nested uuid before nested id' => [[
        'vehicle' => ['driver' => ['uuid' => 'nested-uuid', 'id' => 'nested-id']],
    ], 'nested-uuid'],
    'vehicle driver string' => [[
        'vehicle' => ['driver' => 'driver-public'],
    ], 'driver-public'],
    'top level uuid before driver object' => [[
        'driver_uuid' => 'top-level-driver-uuid',
        'driver'      => ['uuid' => 'driver-object-uuid'],
    ], 'top-level-driver-uuid'],
    'top level object id fallback' => [[
        'driver' => ['id' => 'driver-object-id'],
    ], 'driver-object-id'],
    'no usable identifier' => [[
        'driver' => ['name' => 'Ada Driver'],
    ], null],
]);

test('vehicle controller active order prefers driver current order before last known position', function () {
    $controller = new FleetOpsVehicleControllerProbe();
    $vehicle    = new FleetOpsVehicleActiveOrderFake();
    $vehicle->setRelation('driver', (object) [
        'currentOrder' => (object) ['uuid' => 'current-order-uuid'],
    ]);
    $vehicle->lastPosition = (object) ['order_uuid' => 'position-order-uuid'];

    expect($controller->callHelper('activeOrderUuid', $vehicle))->toBe('current-order-uuid')
        ->and($vehicle->loaded)->toBe(['driver.currentOrder']);
});

test('vehicle controller active order falls back to last known position', function () {
    $controller = new FleetOpsVehicleControllerProbe();
    $vehicle    = new FleetOpsVehicleActiveOrderFake();
    $vehicle->setRelation('driver', (object) ['currentOrder' => null]);
    $vehicle->lastPosition = ['order_uuid' => 'position-order-uuid'];

    expect($controller->callHelper('activeOrderUuid', $vehicle))->toBe('position-order-uuid');
});
