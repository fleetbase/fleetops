<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VehicleController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FleetOpsVehicleControllerProbe extends VehicleController
{
    public ?FleetOpsVehicleEndpointFake $vehicle = null;
    public ?FleetOpsVehicleDriverFake $driver    = null;
    public ?FleetOpsVehicleDeviceFake $device    = null;
    public array $vehicleLookups                 = [];
    public array $driverLookups                  = [];
    public array $deviceLookups                  = [];
    public array $driverSyncs                    = [];

    public function callHelper(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(VehicleController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function findVehicle(string $id): Vehicle
    {
        $this->vehicleLookups[] = ['find', $id];

        return $this->vehicle;
    }

    protected function resolveVehicle(string $id): ?Vehicle
    {
        $this->vehicleLookups[] = ['resolve', $id];

        return $this->vehicle;
    }

    protected function findDriver(string $id): Driver
    {
        $this->driverLookups[] = $id;

        return $this->driver;
    }

    protected function resolveDevice(string $id): ?Device
    {
        $this->deviceLookups[] = $id;

        return $this->device;
    }

    protected function syncDriverAssignment(Vehicle $vehicle, ?string $identifier): void
    {
        $this->driverSyncs[] = [$vehicle, $identifier];

        if (empty($identifier)) {
            $vehicle->unassignDriver();

            return;
        }

        if ($this->driver) {
            $vehicle->assignDriver($this->driver);
            $vehicle->setRelation('driver', $this->driver);
        }
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

class FleetOpsVehicleEndpointFake extends Vehicle
{
    public array $assignedDrivers     = [];
    public bool $unassignedForTest    = false;
    public array $loadedRelations     = [];
    public array $freshRelations      = [];
    public array $customFieldSyncs    = [];

    public function toArray(): array
    {
        return [
            'resource'  => 'vehicle',
            'uuid'      => $this->uuid,
            'public_id' => $this->public_id,
        ];
    }

    public function assignDriver(Driver $driver)
    {
        $this->assignedDrivers[] = $driver;
        $this->forceFill(['driver_uuid' => $driver->uuid]);

        return $this;
    }

    public function unassignDriver(): self
    {
        $this->unassignedForTest = true;
        $this->unsetRelation('driver');

        return $this;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
    }

    public function fresh($with = [])
    {
        $this->freshRelations[] = $with;

        return [
            'resource' => 'vehicle',
            'uuid'     => $this->uuid,
            'with'     => $with,
        ];
    }

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->customFieldSyncs[] = [$payload, $options];

        return $payload;
    }
}

class FleetOpsVehicleDriverFake extends Driver
{
    public function toArray(): array
    {
        return [
            'resource'  => 'driver',
            'uuid'      => $this->uuid,
            'public_id' => $this->public_id,
        ];
    }
}

class FleetOpsVehicleDeviceFake extends Device
{
    public array $attachedVehicles = [];
    public bool $detachedForTest   = false;
    public array $loadedRelations  = [];
    public ?string $throwOnAction  = null;

    public function toArray(): array
    {
        return [
            'resource'        => 'device',
            'uuid'            => $this->uuid,
            'public_id'       => $this->public_id,
            'attachable_uuid' => $this->attachable_uuid,
        ];
    }

    public function attachTo(Fleetbase\Models\Model $attachable): bool
    {
        if ($this->throwOnAction === 'attach') {
            throw new RuntimeException('attach failed');
        }

        $this->attachedVehicles[] = $attachable;
        $this->forceFill([
            'attachable_type' => get_class($attachable),
            'attachable_uuid' => $attachable->uuid,
        ]);

        return true;
    }

    public function detach(): bool
    {
        if ($this->throwOnAction === 'detach') {
            throw new RuntimeException('detach failed');
        }

        $this->detachedForTest = true;
        $this->forceFill([
            'attachable_type' => null,
            'attachable_uuid' => null,
        ]);

        return true;
    }

    public function load($relations)
    {
        $this->loadedRelations[] = $relations;

        return $this;
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

test('vehicle controller assigns and unassigns drivers through endpoint contracts', function () {
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->driver  = new FleetOpsVehicleDriverFake();
    $controller->vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle-public'], true);
    $controller->driver->setRawAttributes(['uuid' => 'driver-uuid', 'public_id' => 'driver-public'], true);

    $assigned = $controller->assignDriver(new Request(['driver' => 'driver-public']), 'vehicle-public')->getData(true);

    expect($assigned)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Driver assigned to vehicle.',
    ])
        ->and($controller->vehicleLookups)->toBe([['find', 'vehicle-public']])
        ->and($controller->driverLookups)->toBe(['driver-public'])
        ->and($controller->vehicle->assignedDrivers)->toBe([$controller->driver])
        ->and($controller->vehicle->loadedRelations)->toBe([['driver', 'devices']]);

    $unassigned = $controller->unassignDriver('vehicle-public')->getData(true);

    expect($unassigned)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Driver unassigned from vehicle.',
    ])
        ->and($controller->vehicle->unassignedForTest)->toBeTrue()
        ->and($controller->vehicle->loadedRelations)->toBe([['driver', 'devices'], ['driver', 'devices']]);
});

test('vehicle controller attaches and detaches devices through endpoint contracts', function () {
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->device  = new FleetOpsVehicleDeviceFake();
    $controller->vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle-public'], true);
    $controller->device->setRawAttributes(['uuid' => 'device-uuid', 'public_id' => 'device-public'], true);

    $attached = $controller->attachDevice(new Request(['device' => 'device-public']), 'vehicle-public')->getData(true);

    expect($attached)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Device attached to vehicle.',
    ])
        ->and($controller->vehicleLookups)->toBe([['resolve', 'vehicle-public']])
        ->and($controller->deviceLookups)->toBe(['device-public'])
        ->and($controller->device->attachedVehicles)->toBe([$controller->vehicle])
        ->and($controller->device->loadedRelations)->toBe([['telematic', 'warranty', 'attachable']])
        ->and($controller->vehicle->loadedRelations)->toBe([['driver', 'devices']]);

    $detached = $controller->detachDevice(new Request(['device' => 'device-public']), 'vehicle-public')->getData(true);

    expect($detached)->toMatchArray([
        'status'  => 'ok',
        'message' => 'Device detached from vehicle.',
    ])
        ->and($controller->device->detachedForTest)->toBeTrue()
        ->and($controller->device->loadedRelations)->toBe([
            ['telematic', 'warranty', 'attachable'],
            ['telematic', 'warranty', 'attachable'],
        ]);
});

test('vehicle controller returns device attachment endpoint errors', function () {
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = null;
    $controller->device  = new FleetOpsVehicleDeviceFake();

    expect($controller->attachDevice(new Request(['device' => 'device-public']), 'missing-vehicle')->getData(true))
        ->toBe(['error' => 'Vehicle not found or not available for this organization.']);

    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->device  = null;

    expect($controller->attachDevice(new Request(['device' => 'missing-device']), 'vehicle-public')->getData(true))
        ->toBe(['error' => 'Device not found or not available for this organization.']);

    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->device  = new FleetOpsVehicleDeviceFake();
    $controller->vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'public_id' => 'vehicle-public'], true);
    $controller->device->setRawAttributes(['uuid' => 'device-uuid', 'attachable_uuid' => 'other-vehicle'], true);

    expect($controller->detachDevice(new Request(['device' => 'device-public']), 'vehicle-public')->getData(true))
        ->toBe(['error' => 'This device is not attached to the selected vehicle.']);

    $controller->device->forceFill(['attachable_uuid' => 'vehicle-uuid']);
    $controller->device->throwOnAction = 'detach';

    expect($controller->detachDevice(new Request(['device' => 'device-public']), 'vehicle-public')->getData(true))
        ->toBe(['error' => 'Unable to detach device from vehicle. Please try again or contact support.']);
});

test('vehicle controller after save syncs driver input and custom fields', function () {
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $vehicle             = new FleetOpsVehicleEndpointFake();

    $controller->afterSave(new Request([
        'vehicle' => [
            'driver'              => ['uuid' => null],
            'custom_field_values' => ['temperature_zone' => 'cold'],
        ],
    ]), $vehicle);

    expect($vehicle->unassignedForTest)->toBeTrue()
        ->and($controller->driverSyncs)->toBe([[$vehicle, null]])
        ->and($vehicle->customFieldSyncs)->toBe([[['temperature_zone' => 'cold'], []]]);
});

test('vehicle controller reports detach lookup failures and attach exceptions', function () {
    // Detach with an unknown vehicle
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = null;
    $controller->device  = new FleetOpsVehicleDeviceFake();
    expect($controller->detachDevice(new Request(['device' => 'device-public']), 'missing-vehicle')->getData(true))
        ->toBe(['error' => 'Vehicle not found or not available for this organization.']);

    // Detach with an unknown device
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->device  = null;
    expect($controller->detachDevice(new Request(['device' => 'missing-device']), 'vehicle-public')->getData(true))
        ->toBe(['error' => 'Device not found or not available for this organization.']);

    // Attach failures log and surface a friendly error
    $controller          = new FleetOpsVehicleControllerProbe();
    $controller->vehicle = new FleetOpsVehicleEndpointFake();
    $controller->device  = new class extends FleetOpsVehicleDeviceFake {
        public function attachTo(Fleetbase\Models\Model $attachable): bool
        {
            throw new RuntimeException('attachment backend offline');
        }
    };
    $response = $controller->attachDevice(new Request(['device' => 'device-public']), 'vehicle-public');
    expect($response->getData(true))->toBe(['error' => 'Unable to attach device to vehicle. Please try again or contact support.'])
        ->and($response->getStatusCode())->toBe(500);
});
