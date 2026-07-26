<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DriverController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\UserDevice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiDriverControllerProbe extends DriverController
{
    public ?FleetOpsApiDriverFake $driver = null;
    public mixed $queryResults            = null;
    public bool $driverNotFound           = false;
    public array $findCalls               = [];
    public array $deviceCreates           = [];

    protected function findDriver(string $id, array $with = []): Driver
    {
        $this->findCalls[] = [$id, $with];

        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        $this->driver ??= new FleetOpsApiDriverFake();
        $this->driver->setAttribute('lookup_id', $id);

        return $this->driver;
    }

    protected function queryDrivers(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'driver-uuid']];
    }

    protected function firstOrCreateUserDevice(array $attributes, array $values): UserDevice
    {
        $this->deviceCreates[] = [$attributes, $values];

        $device = new UserDevice();
        $device->setRawAttributes(['public_id' => 'device_public'], true);

        return $device;
    }

    protected function driverResource(Driver $driver)
    {
        return ['resource' => 'driver', 'driver' => $driver];
    }

    protected function driverResourceCollection($results)
    {
        return ['collection' => 'driver', 'items' => $results];
    }

    protected function deletedDriverResource(Driver $driver)
    {
        return ['resource' => 'deleted-driver', 'driver' => $driver];
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return ['json' => $payload, 'status' => $status];
    }

    protected function apiError(string $message, int $status = 400)
    {
        return ['apiError' => $message, 'status' => $status];
    }
}

class FleetOpsApiDriverFake extends Driver
{
    public array $quietUpdates  = [];
    public array $loaded        = [];
    public bool $deletedForTest = false;

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }

    public function loadMissing($relations)
    {
        $this->loaded[] = $relations;

        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiDriverVehicleFake extends Vehicle
{
    public array $quietUpdates = [];

    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        $this->quietUpdates[] = $attributes;
        $this->setRawAttributes(array_merge($this->getAttributes(), $attributes), true);

        return true;
    }
}

class FleetOpsApiDriverRegisterDeviceRequest extends Request
{
    public function or(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return $this->input($key);
            }
        }

        return $default;
    }
}

test('api driver controller queries finds deletes and tracks empty coordinate payloads', function () {
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'online'    => false,
    ], true);

    $controller               = new FleetOpsApiDriverControllerProbe();
    $controller->driver       = $driver;
    $controller->queryResults = [['uuid' => 'driver-a'], ['uuid' => 'driver-b']];

    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('driver_public');
    $tracked = $controller->track('driver_public', new Request());
    $deleted = $controller->delete('driver_public', new Request());

    expect($query)->toBe([
        'collection' => 'driver',
        'items'      => [['uuid' => 'driver-a'], ['uuid' => 'driver-b']],
    ])
        ->and($found)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($tracked)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($deleted)->toBe(['resource' => 'deleted-driver', 'driver' => $driver])
        ->and($driver->deletedForTest)->toBeTrue()
        ->and($controller->findCalls)->toContain(
            ['driver_public', ['user', 'vehicle', 'vendor', 'currentJob']],
            ['driver_public', []]
        );
});

test('api driver controller toggles driver and vehicle online state', function (mixed $incoming, bool $initial, bool $expected) {
    $vehicle = new FleetOpsApiDriverVehicleFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'online' => !$expected], true);

    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'online'    => $initial,
    ], true);
    $driver->setRelation('vehicle', $vehicle);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    $request  = $incoming === '__missing__' ? new Request() : new Request(['online' => $incoming]);
    $response = $controller->toggleOnline('driver_public', $request);

    expect($response)->toBe(['resource' => 'driver', 'driver' => $driver])
        ->and($driver->quietUpdates)->toContain(['online' => $expected])
        ->and($vehicle->quietUpdates)->toContain(['online' => $expected])
        ->and($driver->loaded)->toContain('vehicle');
})->with([
    'toggles missing input' => ['__missing__', false, true],
    'casts explicit false'  => ['false', true, false],
    'casts explicit true'   => ['1', false, true],
]);

test('api driver controller registers devices and validates required inputs', function () {
    $driver = new FleetOpsApiDriverFake();
    $driver->setRawAttributes([
        'uuid'      => 'driver-uuid',
        'public_id' => 'driver_public',
        'user_uuid' => 'user-uuid',
    ], true);

    $controller         = new FleetOpsApiDriverControllerProbe();
    $controller->driver = $driver;

    expect($controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest()))->toBe([
        'apiError' => 'Token is required to register device.',
        'status'   => 400,
    ])
        ->and($controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest(['token' => 'push-token'])))->toBe([
            'apiError' => 'Platform is required to register device.',
            'status'   => 400,
        ]);

    $response = $controller->registerDevice('driver_public', new FleetOpsApiDriverRegisterDeviceRequest([
        'token'    => 'push-token',
        'platform' => 'ios',
    ]));

    expect($response)->toBe([
        'json'   => ['device' => 'device_public'],
        'status' => 200,
    ])
        ->and($controller->deviceCreates)->toBe([
            [
                ['token' => 'push-token', 'platform' => 'ios'],
                ['user_uuid' => 'user-uuid', 'platform' => 'ios', 'token' => 'push-token', 'status' => 'active'],
            ],
        ]);
});

test('api driver controller reports missing driver branches', function () {
    $controller                 = new FleetOpsApiDriverControllerProbe();
    $controller->driverNotFound = true;

    $json404 = [
        'json'   => ['error' => 'Driver resource not found.'],
        'status' => 404,
    ];

    expect($controller->find('missing-driver'))->toBe($json404)
        ->and($controller->delete('missing-driver', new Request()))->toBe($json404)
        ->and($controller->toggleOnline('missing-driver', new Request()))->toBe($json404)
        ->and($controller->registerDevice('missing-driver', new FleetOpsApiDriverRegisterDeviceRequest()))->toBe($json404)
        ->and($controller->track('missing-driver', new Request(['latitude' => 1, 'longitude' => 2])))->toBe([
            'apiError' => 'Driver resource not found.',
            'status'   => 404,
        ]);
});
