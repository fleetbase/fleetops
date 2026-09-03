<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\DeviceController;
use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\File;
use Fleetbase\Models\Model as FleetbaseModel;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiDeviceControllerEndpointProbe extends DeviceController
{
    public ?FleetOpsApiDeviceEndpointFake $createdDevice = null;
    public array $models                                 = [];
    public array $creates                                = [];
    public array $resources                              = [];
    public array $deletedResources                       = [];
    public array $collections                            = [];
    public array $queries                                = [];
    public array $lookupLogs                             = [];
    public array $failureLogs                            = [];

    protected function createDevice(array $input): Device
    {
        $this->creates[] = $input;

        return $this->createdDevice;
    }

    protected function queryDevicesWithRequest(Request $request, callable $callback): mixed
    {
        $query = new FleetOpsApiDeviceQueryFake();
        $callback($query);
        $this->queries[] = $query->calls;

        return [['uuid' => 'device-a'], ['uuid' => 'device-b']];
    }

    protected function resolveModel(string $modelClass, string $id): EloquentModel
    {
        $key = $modelClass . ':' . $id;

        if (!array_key_exists($key, $this->models)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return $this->models[$key];
    }

    protected function deviceResource(Device $device): mixed
    {
        $this->resources[] = $device->uuid;

        return [
            'uuid'      => $device->uuid,
            'public_id' => $device->public_id,
            'status'    => $device->status,
        ];
    }

    protected function deviceResourceCollection(mixed $results): mixed
    {
        $this->collections[] = $results;

        return ['collection' => $results];
    }

    protected function deletedDeviceResource(Device $device): mixed
    {
        $this->deletedResources[] = $device->uuid;

        return ['deleted' => $device->uuid];
    }

    protected function logDeviceAttachmentLookupFailure(string $action, string $deviceId, ?string $vehicleId): void
    {
        $this->lookupLogs[] = [$action, $deviceId, $vehicleId];
    }

    protected function logDeviceAttachmentFailure(string $action, Device $device, ?EloquentModel $attachable, Throwable $exception): void
    {
        $this->failureLogs[] = [$action, $device->uuid, $attachable?->uuid, $exception->getMessage()];
    }
}

class FleetOpsApiDeviceEndpointFake extends Device
{
    public array $loads       = [];
    public array $loadCounts  = [];
    public array $updates     = [];
    public array $attachments = [];
    public array $detaches    = [];
    public bool $deleted      = false;
    public bool $throwAttach  = false;
    public bool $throwDetach  = false;

    public function load($relations)
    {
        $this->loads[] = $relations;

        return $this;
    }

    public function loadCount($relations)
    {
        $this->loadCounts[] = $relations;

        return $this;
    }

    public function refresh()
    {
        return $this;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }

    public function delete()
    {
        $this->deleted = true;

        return true;
    }

    public function attachTo(FleetbaseModel $attachable): bool
    {
        if ($this->throwAttach) {
            throw new RuntimeException('attach exploded');
        }

        $this->attachments[] = [$attachable::class, $attachable->uuid];

        return true;
    }

    public function detach(): bool
    {
        if ($this->throwDetach) {
            throw new RuntimeException('detach exploded');
        }

        $this->detaches[] = $this->uuid;

        return true;
    }
}

class FleetOpsApiDeviceRelatedFake extends EloquentModel
{
    protected $guarded = [];
}

class FleetOpsApiDeviceQueryFake
{
    public array $calls = [];

    public function with($relations): self
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }

    public function withCount($relations): self
    {
        $this->calls[] = ['withCount', $relations];

        return $this;
    }
}

function fleetopsApiDeviceControllerEndpoint(): FleetOpsApiDeviceControllerEndpointProbe
{
    $controller                = new FleetOpsApiDeviceControllerEndpointProbe();
    $controller->createdDevice = fleetopsApiDeviceEndpointDevice('created-device', 'created_device');

    return $controller;
}

function fleetopsApiDeviceEndpointDevice(string $uuid = 'device-uuid', string $publicId = 'device_public'): FleetOpsApiDeviceEndpointFake
{
    $device = new FleetOpsApiDeviceEndpointFake();
    $device->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
        'status'    => 'active',
    ], true);
    $device->setAppends([]);

    return $device;
}

function fleetopsApiDeviceEndpointVehicle(string $uuid = 'vehicle-uuid', string $publicId = 'vehicle_public'): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'      => $uuid,
        'public_id' => $publicId,
    ], true);

    return $vehicle;
}

function fleetopsApiDeviceRelated(string $uuid): FleetOpsApiDeviceRelatedFake
{
    $model = new FleetOpsApiDeviceRelatedFake();
    $model->setRawAttributes(['uuid' => $uuid], true);

    return $model;
}

test('api device controller creates queries updates finds and deletes devices', function () {
    session(['company' => 'company-uuid']);

    $controller = fleetopsApiDeviceControllerEndpoint();
    $device     = fleetopsApiDeviceEndpointDevice();

    $controller->models = [
        Telematic::class . ':telematic_public' => fleetopsApiDeviceRelated('telematic-uuid'),
        Warranty::class . ':warranty_public'   => fleetopsApiDeviceRelated('warranty-uuid'),
        File::class . ':photo_public'          => fleetopsApiDeviceRelated('photo-uuid'),
        Device::class . ':device_public'       => $device,
    ];

    $created = $controller->create(CreateDeviceRequest::create('/devices', 'POST', [
        'device_id'  => 'gps-001',
        'type'       => 'gps',
        'status'     => 'active',
        'latitude'   => '1.25',
        'longitude'  => '103.82',
        'telematic'  => 'telematic_public',
        'warranty'   => 'warranty_public',
        'photo'      => 'photo_public',
        'attachable' => '',
    ]));
    $query   = $controller->query(new Request(['limit' => 2]));
    $updated = $controller->update('device_public', UpdateDeviceRequest::create('/devices/device_public', 'PUT', [
        'name'       => 'Updated Tracker',
        'online'     => true,
        'telematic'  => '',
        'attachable' => '',
    ]));
    $found   = $controller->find('device_public');
    $deleted = $controller->delete('device_public');

    expect($created)->toBe(['uuid' => 'created-device', 'public_id' => 'created_device', 'status' => 'active'])
        ->and($controller->creates[0])->toMatchArray([
            'device_id'       => 'gps-001',
            'type'            => 'gps',
            'status'          => 'active',
            'company_uuid'    => 'company-uuid',
            'telematic_uuid'  => 'telematic-uuid',
            'warranty_uuid'   => 'warranty-uuid',
            'photo_uuid'      => 'photo-uuid',
            'attachable_type' => null,
            'attachable_uuid' => null,
        ])
        ->and($controller->creates[0]['last_position']->getLat())->toBe(1.25)
        ->and($controller->creates[0]['last_position']->getLng())->toBe(103.82)
        ->and($query)->toBe(['collection' => [['uuid' => 'device-a'], ['uuid' => 'device-b']]])
        ->and($controller->queries[0])->toBe([
            ['with', ['telematic', 'warranty', 'attachable', 'photo']],
            ['withCount', 'sensors'],
        ])
        ->and($updated)->toBe(['uuid' => 'device-uuid', 'public_id' => 'device_public', 'status' => 'active'])
        ->and($device->updates[0])->toMatchArray([
            'name'            => 'Updated Tracker',
            'online'          => true,
            'telematic_uuid'  => null,
            'attachable_type' => null,
            'attachable_uuid' => null,
        ])
        ->and($found)->toBe(['uuid' => 'device-uuid', 'public_id' => 'device_public', 'status' => 'active'])
        ->and($deleted)->toBe(['deleted' => 'device-uuid'])
        ->and($device->deleted)->toBeTrue()
        ->and($controller->createdDevice->loads)->toHaveCount(1)
        ->and($controller->createdDevice->loadCounts)->toBe(['sensors'])
        ->and($device->loads)->toHaveCount(2)
        ->and($device->loadCounts)->toBe(['sensors', 'sensors']);
});

test('api device controller attaches detaches and reports failures', function () {
    $controller = fleetopsApiDeviceControllerEndpoint();
    $device     = fleetopsApiDeviceEndpointDevice();
    $vehicle    = fleetopsApiDeviceEndpointVehicle();

    $controller->models = [
        Device::class . ':device_public'   => $device,
        Vehicle::class . ':vehicle_public' => $vehicle,
    ];

    $attached = $controller->attach(new Request(['vehicle' => 'vehicle_public']), 'device_public')->getData(true);
    $detached = $controller->detach('device_public')->getData(true);

    $missing = $controller->update('missing_device', UpdateDeviceRequest::create('/devices/missing_device', 'PUT', [
        'name' => 'Missing',
    ]))->getData(true);

    $attachFailureDevice              = fleetopsApiDeviceEndpointDevice('attach-failure');
    $attachFailureDevice->throwAttach = true;
    $attachFailureController          = fleetopsApiDeviceControllerEndpoint();
    $attachFailureController->models  = [
        Device::class . ':attach_failure'  => $attachFailureDevice,
        Vehicle::class . ':vehicle_public' => $vehicle,
    ];
    $attachFailure = $attachFailureController->attach(new Request(['attachable' => 'vehicle_public']), 'attach_failure')->getData(true);

    $detachFailureDevice              = fleetopsApiDeviceEndpointDevice('detach-failure');
    $detachFailureDevice->throwDetach = true;
    $detachFailureController          = fleetopsApiDeviceControllerEndpoint();
    $detachFailureController->models  = [
        Device::class . ':detach_failure' => $detachFailureDevice,
    ];
    $detachFailure = $detachFailureController->detach('detach_failure')->getData(true);

    $lookupFailure = fleetopsApiDeviceControllerEndpoint()
        ->attach(new Request(['vehicle' => 'missing_vehicle']), 'missing_device')
        ->getData(true);

    expect($attached)->toBe([
        'status' => 'ok',
        'device' => ['uuid' => 'device-uuid', 'public_id' => 'device_public', 'status' => 'active'],
    ])
        ->and($device->attachments)->toBe([[Vehicle::class, 'vehicle-uuid']])
        ->and($detached)->toBe([
            'status' => 'ok',
            'device' => ['uuid' => 'device-uuid', 'public_id' => 'device_public', 'status' => 'active'],
        ])
        ->and($device->detaches)->toBe(['device-uuid'])
        ->and($missing)->toBe(['error' => 'Device resource not found.'])
        ->and($attachFailure)->toBe(['error' => 'Unable to attach device to resource.'])
        ->and($attachFailureController->failureLogs)->toBe([['attach', 'attach-failure', 'vehicle-uuid', 'attach exploded']])
        ->and($detachFailure)->toBe(['error' => 'Unable to detach device from resource.'])
        ->and($detachFailureController->failureLogs)->toBe([['detach', 'detach-failure', null, 'detach exploded']])
        ->and($lookupFailure)->toBe(['error' => 'Device or attachable resource not found.']);
});
