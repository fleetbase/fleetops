<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Models\Model;
use Illuminate\Http\Request;

class FleetOpsInternalDeviceControllerProbe extends DeviceController
{
    public ?FleetOpsInternalDeviceFake $device = null;
    public ?Vehicle $vehicle                   = null;
    public array $deviceLookups                = [];
    public array $vehicleLookups               = [];
    public static array $vehicleUuidLookups    = [];
    public static array $vehicleUuids          = ['vehicle-uuid'];

    protected function resolveDevice(string $id): ?Device
    {
        $this->deviceLookups[] = $id;

        return $this->device;
    }

    protected function resolveVehicle(?string $id): ?Vehicle
    {
        $this->vehicleLookups[] = $id;

        return $this->vehicle;
    }

    protected static function vehicleUuidsForPublicId(string $vehicle)
    {
        static::$vehicleUuidLookups[] = [$vehicle, session('company')];

        return collect(static::$vehicleUuids);
    }
}

class FleetOpsInternalDeviceFake extends Device
{
    public array $attachedTo = [];
    public array $detaches   = [];
    public array $loads      = [];
    public bool $throwAttach = false;
    public bool $throwDetach = false;

    public function attachTo(Model $attachable): bool
    {
        if ($this->throwAttach) {
            throw new RuntimeException('attach failed');
        }

        $this->attachedTo[] = [$attachable::class, $attachable->uuid];
        $this->forceFill([
            'attachable_type' => $attachable::class,
            'attachable_uuid' => $attachable->uuid,
        ]);

        return true;
    }

    public function detach(): bool
    {
        if ($this->throwDetach) {
            throw new RuntimeException('detach failed');
        }

        $this->detaches[] = $this->uuid;
        $this->forceFill([
            'attachable_type' => null,
            'attachable_uuid' => null,
        ]);

        return true;
    }

    public function load($relations)
    {
        $this->loads[] = $relations;

        return $this;
    }
}

class FleetOpsInternalDeviceQueryRecorder
{
    public array $calls = [];

    public function with($relations)
    {
        $this->calls[] = ['with', $relations];

        return $this;
    }

    public function withCount($relation)
    {
        $this->calls[] = ['withCount', $relation];

        return $this;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->calls[] = ['where', $column, $operator, $value, $boolean];

        if (is_callable($column)) {
            $column($this);
        }

        return $this;
    }

    public function whereNotNull(string $column)
    {
        $this->calls[] = ['whereNotNull', $column];

        return $this;
    }

    public function whereNull(string $column)
    {
        $this->calls[] = ['whereNull', $column];

        return $this;
    }

    public function orWhere(string $column, $operator = null, $value = null)
    {
        $this->calls[] = ['orWhere', $column, $operator, $value];

        return $this;
    }

    public function orWhereIn(string $column, $values)
    {
        $this->calls[] = ['orWhereIn', $column, $values instanceof Illuminate\Support\Collection ? $values->all() : $values];

        return $this;
    }

    public function orWhereBetween(string $column, array $values)
    {
        $this->calls[] = ['orWhereBetween', $column, $values];

        return $this;
    }

    public function orWhereNull(string $column)
    {
        $this->calls[] = ['orWhereNull', $column];

        return $this;
    }

    public function whereBetween(string $column, array $values)
    {
        $this->calls[] = ['whereBetween', $column, $values];

        return $this;
    }

    public function whereDate(string $column, $value)
    {
        $this->calls[] = ['whereDate', $column, $value];

        return $this;
    }
}

function fleetopsInternalDeviceController(?FleetOpsInternalDeviceFake $device = null, ?Vehicle $vehicle = null): FleetOpsInternalDeviceControllerProbe
{
    FleetOpsInternalDeviceControllerProbe::$vehicleUuidLookups = [];
    FleetOpsInternalDeviceControllerProbe::$vehicleUuids       = ['vehicle-uuid'];

    $controller          = new FleetOpsInternalDeviceControllerProbe();
    $controller->device  = $device;
    $controller->vehicle = $vehicle;

    return $controller;
}

function fleetopsInternalDevice(): FleetOpsInternalDeviceFake
{
    $device = new FleetOpsInternalDeviceFake();
    $device->setRawAttributes([
        'uuid'             => 'device-uuid',
        'public_id'        => 'device_public',
        'company_uuid'     => 'company-uuid',
        'attachable_type'  => null,
        'attachable_uuid'  => null,
    ], true);
    $device->setAppends([]);

    return $device;
}

function fleetopsInternalDeviceVehicle(): Vehicle
{
    $vehicle = new Vehicle();
    $vehicle->setRawAttributes([
        'uuid'         => 'vehicle-uuid',
        'public_id'    => 'vehicle_public',
        'company_uuid' => 'company-uuid',
    ], true);

    return $vehicle;
}

function fleetopsInternalDeviceJson(mixed $response): array
{
    return $response->getData(true);
}

test('internal device filter contract remains aligned with model, filter, and controller', function () {
    $filter     = file_get_contents(dirname(__DIR__, 4) . '/src/Http/Filter/DeviceFilter.php');
    $model      = file_get_contents(dirname(__DIR__, 4) . '/src/Models/Device.php');
    $controller = file_get_contents(dirname(__DIR__, 4) . '/src/Http/Controllers/Internal/v1/DeviceController.php');

    expect($model)
        ->toContain("'vehicle'")
        ->toContain("'connection_status'")
        ->toContain("'device_id'")
        ->toContain("'type'")
        ->toContain("'serial_number'")
        ->toContain("'last_online_at'")
        ->toContain("'updated_at'");

    expect($filter)
        ->toContain('public function query(?string $searchQuery)')
        ->toContain('public function deviceId(?string $deviceId)')
        ->toContain("where('device_id', 'like'")
        ->toContain('public function type(string|array|null $type)')
        ->toContain("whereIn('type', \$type)")
        ->toContain('public function serialNumber(?string $serialNumber)')
        ->toContain("where('serial_number', 'like'")
        ->toContain('public function vehicle(?string $vehicle)')
        ->toContain("wherePublicRelation('attachable_uuid', Vehicle::class, \$vehicle)")
        ->toContain('public function connectionStatus')
        ->toContain("'online'")
        ->toContain("'recently_offline'")
        ->toContain("'offline'")
        ->toContain("'long_offline'")
        ->toContain("'never_connected'")
        ->toContain('public function lastOnlineAt')
        ->toContain('public function updatedAt')
        ->toContain('Utils::dateRange')
        ->toContain('protected function filterDate');

    expect($controller)
        ->toContain("withCount('sensors')")
        ->toContain("filled('connection_status')")
        ->toContain("filled('serial_number')")
        ->toContain("filled('last_online_at')")
        ->toContain("filled('updated_at')");
});

test('internal device query callback applies relationship, attachment, identifier, status, date, and vehicle filters', function () {
    session(['company' => 'company-uuid']);

    $query = new FleetOpsInternalDeviceQueryRecorder();

    FleetOpsInternalDeviceControllerProbe::onQueryRecord($query, new Request([
        'attachment_state'  => 'attached',
        'vehicle'           => 'vehicle_public',
        'device_id'         => 'device-123',
        'serial_number'     => 'serial-456',
        'connection_status' => ['online', 'recently_offline', 'offline', 'long_offline', 'never_connected', 'ignored'],
        'last_online_at'    => ['2026-01-01', '2026-01-31'],
        'updated_at'        => '2026-02-01',
    ]));

    expect($query->calls)
        ->toContain(['with', ['telematic', 'warranty', 'attachable']])
        ->toContain(['withCount', 'sensors'])
        ->toContain(['whereNotNull', 'attachable_uuid'])
        ->toContain(['where', 'device_id', 'like', '%device-123%', 'and'])
        ->toContain(['where', 'serial_number', 'like', '%serial-456%', 'and'])
        ->toContain(['orWhereIn', 'attachable_uuid', ['vehicle-uuid']])
        ->and(FleetOpsInternalDeviceControllerProbe::$vehicleUuidLookups)->toBe([['vehicle_public', 'company-uuid']]);

    expect(collect($query->calls)->where(0, 'orWhere')->values()->all())->toHaveCount(2)
        ->and(collect($query->calls)->where(0, 'orWhereBetween')->values()->all())->toHaveCount(2)
        ->and(collect($query->calls)->where(0, 'orWhereNull')->values()->all())->toBe([['orWhereNull', 'last_online_at']])
        ->and(collect($query->calls)->where(0, 'whereBetween')->values()->all())->toHaveCount(1)
        ->and(collect($query->calls)->where(0, 'whereDate')->values()->all())->toHaveCount(1);

    $unattachedQuery = new FleetOpsInternalDeviceQueryRecorder();
    FleetOpsInternalDeviceControllerProbe::onQueryRecord($unattachedQuery, new Request([
        'attachment_state' => 'unattached',
    ]));

    expect($unattachedQuery->calls)->toContain(['whereNull', 'attachable_uuid']);
});

test('internal device find callback loads attachment context', function () {
    $query = new FleetOpsInternalDeviceQueryRecorder();

    FleetOpsInternalDeviceControllerProbe::onFindRecord($query, new Request());

    expect($query->calls)
        ->toContain(['with', ['telematic', 'warranty', 'attachable']])
        ->toContain(['withCount', 'sensors']);
});

test('internal device controller attaches and detaches devices through resolved resources', function () {
    $device     = fleetopsInternalDevice();
    $vehicle    = fleetopsInternalDeviceVehicle();
    $controller = fleetopsInternalDeviceController($device, $vehicle);

    $attached = fleetopsInternalDeviceJson($controller->attach(new Request(['vehicle' => 'vehicle_public']), 'device_public'));
    $detached = fleetopsInternalDeviceJson($controller->detach('device_public'));

    expect($attached['status'])->toBe('ok');
    expect($attached['device']['uuid'])->toBe('device-uuid');
    expect($attached['device']['public_id'])->toBe('device_public');
    expect($attached['device']['attachable_uuid'])->toBe('vehicle-uuid');

    expect($detached['status'])->toBe('ok');
    expect($detached['device']['uuid'])->toBe('device-uuid');
    expect($detached['device']['public_id'])->toBe('device_public');
    expect($detached['device']['attachable_uuid'])->toBeNull();

    expect($controller->deviceLookups)->toBe(['device_public', 'device_public']);
    expect($controller->vehicleLookups)->toBe(['vehicle_public']);
    expect($device->attachedTo)->toBe([[Vehicle::class, 'vehicle-uuid']]);
    expect($device->detaches)->toBe(['device-uuid']);
    expect($device->loads)->toBe([
        ['telematic', 'warranty', 'attachable'],
        ['telematic', 'warranty', 'attachable'],
    ]);
});

test('internal device controller reports lookup and persistence failures', function () {
    $missingDevice = fleetopsInternalDeviceJson(
        fleetopsInternalDeviceController(null, fleetopsInternalDeviceVehicle())
            ->attach(new Request(['vehicle' => 'vehicle_public']), 'missing_device')
    );

    $missingVehicle = fleetopsInternalDeviceJson(
        fleetopsInternalDeviceController(fleetopsInternalDevice(), null)
            ->attach(new Request(['attachable_uuid' => 'missing_vehicle']), 'device_public')
    );

    $attachFailureDevice              = fleetopsInternalDevice();
    $attachFailureDevice->throwAttach = true;
    $attachFailure                    = fleetopsInternalDeviceJson(
        fleetopsInternalDeviceController($attachFailureDevice, fleetopsInternalDeviceVehicle())
            ->attach(new Request(['vehicle' => 'vehicle_public']), 'device_public')
    );

    $detachFailureDevice              = fleetopsInternalDevice();
    $detachFailureDevice->throwDetach = true;
    $detachFailure                    = fleetopsInternalDeviceJson(
        fleetopsInternalDeviceController($detachFailureDevice)
            ->detach('device_public')
    );

    $missingDetachDevice = fleetopsInternalDeviceJson(
        fleetopsInternalDeviceController(null)
            ->detach('missing_device')
    );

    expect($missingDevice['error'])->toBe('Device not found or not available for this organization.')
        ->and($missingVehicle['error'])->toBe('Vehicle not found or not available for this organization.')
        ->and($attachFailure['error'])->toBe('Unable to attach device to vehicle. Please try again or contact support.')
        ->and($detachFailure['error'])->toBe('Unable to detach device from vehicle. Please try again or contact support.')
        ->and($missingDetachDevice['error'])->toBe('Device not found or not available for this organization.');
});
