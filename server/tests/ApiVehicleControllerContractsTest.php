<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVehicleRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiVehicleCrudControllerProbe extends VehicleController
{
    public ?FleetOpsApiVehicleCrudFake $vehicle = null;
    public ?FleetOpsApiDriverCrudFake $driver   = null;
    public array $createdVehicles               = [];
    public array $vendorLookups                 = [];
    public mixed $queryResults                  = null;
    public bool $vehicleNotFound                = false;
    public bool $driverNotFound                 = false;

    public function inputForTest(Request $request): array
    {
        return $this->vehicleInputFromRequest($request);
    }

    protected function getVendorUuid(string $table, array $where): ?string
    {
        $this->vendorLookups[] = [$table, $where];

        return 'vendor-uuid';
    }

    protected function createVehicle(array $input): Vehicle
    {
        $this->createdVehicles[] = $input;

        $vehicle = new FleetOpsApiVehicleCrudFake();
        $vehicle->setRawAttributes(array_merge(['uuid' => 'created-vehicle-uuid'], $input));

        return $vehicle;
    }

    protected function findVehicle(string $id): Vehicle
    {
        if ($this->vehicleNotFound) {
            throw new ModelNotFoundException();
        }

        $this->vehicle?->setAttribute('lookup_id', $id);

        return $this->vehicle;
    }

    protected function findDriver(string $id): Driver
    {
        if ($this->driverNotFound) {
            throw new ModelNotFoundException();
        }

        $this->driver ??= new FleetOpsApiDriverCrudFake();
        $this->driver->setRawAttributes(['uuid' => 'driver-uuid', 'lookup_id' => $id]);

        return $this->driver;
    }

    protected function queryVehicles(Request $request)
    {
        return $this->queryResults ?? [['uuid' => 'vehicle-uuid']];
    }

    protected function vehicleResource(Vehicle $vehicle)
    {
        return ['resource' => 'vehicle', 'vehicle' => $vehicle];
    }

    protected function vehicleResourceCollection($results)
    {
        return ['collection' => 'vehicle', 'items' => $results];
    }

    protected function deletedVehicleResource(Vehicle $vehicle)
    {
        return ['resource' => 'deleted-vehicle', 'vehicle' => $vehicle];
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

class FleetOpsApiVehicleCrudFake extends Vehicle
{
    public array $assignedDrivers  = [];
    public array $filled           = [];
    public bool $unassignedDriver  = false;
    public bool $savedForTest      = false;
    public bool $deletedForTest    = false;
    public bool $dirtyVinForTest   = false;
    public bool $vinAppliedForTest = false;

    public function assignDriver(Driver $driver): void
    {
        $this->assignedDrivers[] = $driver;
    }

    public function unassignDriver(): Vehicle
    {
        $this->unassignedDriver = true;

        return $this;
    }

    public function fill(array $attributes)
    {
        $this->filled[] = $attributes;

        return parent::fill($attributes);
    }

    public function isDirty($attributes = null): bool
    {
        return $attributes === 'vin' ? $this->dirtyVinForTest : parent::isDirty($attributes);
    }

    public function applyAllDataFromVin()
    {
        $this->vinAppliedForTest = true;

        return $this;
    }

    public function save(array $options = []): bool
    {
        $this->savedForTest = true;

        return true;
    }

    public function refresh()
    {
        return $this;
    }

    public function delete()
    {
        $this->deletedForTest = true;

        return true;
    }
}

class FleetOpsApiDriverCrudFake extends Driver
{
}

class FleetOpsUpdateVehicleRequestFake extends UpdateVehicleRequest
{
    public function __construct(private array $payload)
    {
        parent::__construct();
    }

    public function only($keys)
    {
        return collect($this->payload)->only(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function except($keys)
    {
        return collect($this->payload)->except(is_array($keys) ? $keys : func_get_args())->all();
    }

    public function has($key): bool
    {
        if (is_array($key)) {
            foreach ($key as $item) {
                if (!array_key_exists($item, $this->payload)) {
                    return false;
                }
            }

            return true;
        }

        return array_key_exists($key, $this->payload);
    }

    public function exists($key): bool
    {
        return array_key_exists($key, $this->payload);
    }

    public function input($key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->payload;
        }

        return $this->payload[$key] ?? $default;
    }
}

function fleetopsCreateVehicleRequest(array $input): CreateVehicleRequest
{
    return CreateVehicleRequest::create('/api/v1/vehicles', 'POST', $input);
}

function fleetopsUpdateVehicleRequest(array $input): UpdateVehicleRequest
{
    return new FleetOpsUpdateVehicleRequestFake($input);
}

test('api vehicle controller creates vehicles with defaults relation lookups and driver assignment', function () {
    session(['company' => 'company-uuid']);

    $driver             = new FleetOpsApiDriverCrudFake();
    $controller         = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->driver = $driver;

    $response = $controller->create(fleetopsCreateVehicleRequest([
        'status'       => 'active',
        'make'         => 'Toyota',
        'model'        => 'HiAce',
        'year'         => 2025,
        'plate_number' => 'SG-1234',
        'vin'          => 'VIN123',
        'latitude'     => 1.2816,
        'longitude'    => 103.851,
        'vendor'       => 'vendor-public',
        'driver'       => 'driver-public',
        'ignored'      => 'not copied',
    ]));

    $vehicle = $response['vehicle'];

    expect($response['resource'])->toBe('vehicle')
        ->and($controller->vendorLookups)->toBe([
            ['vendors', ['public_id' => 'vendor-public', 'company_uuid' => 'company-uuid']],
        ])
        ->and($controller->createdVehicles[0])->toMatchArray([
            'status'       => 'active',
            'make'         => 'Toyota',
            'model'        => 'HiAce',
            'year'         => 2025,
            'plate_number' => 'SG-1234',
            'vin'          => 'VIN123',
            'company_uuid' => 'company-uuid',
            'online'       => 0,
            'vendor_uuid'  => 'vendor-uuid',
        ])
        ->and($controller->createdVehicles[0])->toHaveKey('location')
        ->and($vehicle->assignedDrivers)->toBe([$driver])
        ->and($controller->createdVehicles[0])->not->toHaveKey('ignored');
});

test('api vehicle controller updates queries finds deletes and tracks empty coordinate payloads', function () {
    session(['company' => 'company-uuid']);

    $vehicle = new FleetOpsApiVehicleCrudFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'vin' => 'OLDVIN']);

    $controller               = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->vehicle      = $vehicle;
    $controller->queryResults = [['uuid' => 'vehicle-a'], ['uuid' => 'vehicle-b']];

    $updateRequest = fleetopsUpdateVehicleRequest([
        'make'      => 'Nissan',
        'model'     => 'NV350',
        'vin'       => 'NEWVIN',
        'vendor'    => 'vendor-public',
        'driver'    => '',
        'latitude'  => 1.3,
        'longitude' => 103.8,
    ]);

    $updated = $controller->update('vehicle-public', $updateRequest);
    $query   = $controller->query(new Request(['limit' => 2]));
    $found   = $controller->find('vehicle-public');
    $tracked = $controller->track('vehicle-public', new Request());
    $deleted = $controller->delete('vehicle-public');

    $filledInput = $vehicle->filled[array_key_last($vehicle->filled)];

    expect($updated)->toBe(['resource' => 'vehicle', 'vehicle' => $vehicle])
        ->and($filledInput)->toMatchArray([
            'make'        => 'Nissan',
            'model'       => 'NV350',
            'vin'         => 'NEWVIN',
            'online'      => 0,
            'vendor_uuid' => 'vendor-uuid',
        ])
        ->and($filledInput)->toHaveKey('location')
        ->and($vehicle->unassignedDriver)->toBeTrue()
        ->and($vehicle->savedForTest)->toBeTrue()
        ->and($query)->toBe([
            'collection' => 'vehicle',
            'items'      => [['uuid' => 'vehicle-a'], ['uuid' => 'vehicle-b']],
        ])
        ->and($found)->toBe(['resource' => 'vehicle', 'vehicle' => $vehicle])
        ->and($tracked)->toBe(['resource' => 'vehicle', 'vehicle' => $vehicle])
        ->and($deleted)->toBe(['resource' => 'deleted-vehicle', 'vehicle' => $vehicle])
        ->and($vehicle->lookup_id)->toBe('vehicle-public')
        ->and($vehicle->deletedForTest)->toBeTrue();
});

test('api vehicle controller updates rerun vin decoding and assign drivers', function () {
    session(['company' => 'company-uuid']);

    $vehicle = new FleetOpsApiVehicleCrudFake();
    $vehicle->setRawAttributes(['uuid' => 'vehicle-uuid', 'vin' => 'OLDVIN']);
    $vehicle->dirtyVinForTest = true;

    $driver              = new FleetOpsApiDriverCrudFake();
    $controller          = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->vehicle = $vehicle;
    $controller->driver  = $driver;

    $updated = $controller->update('vehicle-public', fleetopsUpdateVehicleRequest([
        'vin'    => 'NEWVIN',
        'driver' => 'driver-public',
    ]));

    expect($updated)->toBe(['resource' => 'vehicle', 'vehicle' => $vehicle])
        ->and($vehicle->vinAppliedForTest)->toBeTrue()
        ->and($vehicle->assignedDrivers)->toBe([$driver])
        ->and($vehicle->unassignedDriver)->toBeFalse();
});

test('api vehicle controller reports missing vehicle and missing driver branches', function () {
    $controller                  = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->vehicleNotFound = true;

    $expectedVehicleJson = [
        'json'   => ['error' => 'Vehicle resource not found.'],
        'status' => 404,
    ];

    expect($controller->update('missing-vehicle', fleetopsUpdateVehicleRequest(['make' => 'Missing'])))->toBe($expectedVehicleJson)
        ->and($controller->find('missing-vehicle'))->toBe($expectedVehicleJson)
        ->and($controller->delete('missing-vehicle'))->toBe($expectedVehicleJson)
        ->and($controller->track('missing-vehicle', new Request(['latitude' => 1, 'longitude' => 2])))->toBe([
            'apiError' => 'Vehicle resource not found.',
            'status'   => 404,
        ]);

    $controller                 = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->driverNotFound = true;

    expect($controller->create(fleetopsCreateVehicleRequest(['make' => 'Van', 'driver' => 'missing-driver'])))->toBe([
        'json'   => ['error' => 'The driver attempted to assign this vehicle was not found.'],
        'status' => 404,
    ]);

    $controller                 = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->vehicle        = new FleetOpsApiVehicleCrudFake();
    $controller->driverNotFound = true;

    expect($controller->update('vehicle-public', fleetopsUpdateVehicleRequest(['driver' => 'missing-driver'])))->toBe([
        'json'   => ['error' => 'The driver attempted to assign this vehicle was not found.'],
        'status' => 404,
    ]);
});
