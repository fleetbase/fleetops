<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VehicleController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

class FleetOpsVehicleLoggerFake
{
    public array $warnings = [];
    public array $errors   = [];

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = [$message, $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->errors[] = [$message, $context];
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

test('vehicle controller records device attachment lookup and exception context', function () {
    session(['company' => 'company-uuid']);
    app()->instance('request', Request::create('/vehicles/vehicle-public/devices', 'POST', [], [], [], [
        'HTTP_X_REQUEST_ID' => 'request-123',
    ]));
    $logger = new FleetOpsVehicleLoggerFake();
    app()->instance('log', $logger);
    Log::clearResolvedInstance('log');

    $controller = new FleetOpsVehicleControllerProbe();
    $vehicle    = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'      => 'vehicle-uuid',
        'public_id' => 'vehicle-public',
    ], true);
    $device = new Device();
    $device->setRawAttributes([
        'uuid'      => 'device-uuid',
        'public_id' => 'device-public',
    ], true);

    $controller->callHelper('logDeviceAttachmentLookupFailure', 'attach-device', 'device', 'vehicle-public', 'device-public');
    $controller->callHelper('logDeviceAttachmentFailure', 'detach-device', $vehicle, $device, new RuntimeException('attach failed'));

    expect($logger->warnings)->toBe([
        [
            'Vehicle device attachment lookup failed',
            [
                'action'           => 'attach-device',
                'missing_resource' => 'device',
                'vehicle_id'       => 'vehicle-public',
                'device_id'        => 'device-public',
                'company_uuid'     => 'company-uuid',
                'request_id'       => 'request-123',
            ],
        ],
    ])->and($logger->errors)->toBe([
        [
            'Vehicle device attachment failed',
            [
                'action'          => 'detach-device',
                'vehicle_uuid'    => 'vehicle-uuid',
                'vehicle_id'      => 'vehicle-public',
                'device_uuid'     => 'device-uuid',
                'device_id'       => 'device-public',
                'company_uuid'    => 'company-uuid',
                'request_id'      => 'request-123',
                'exception_class' => RuntimeException::class,
                'exception'       => 'attach failed',
            ],
        ],
    ]);
});
