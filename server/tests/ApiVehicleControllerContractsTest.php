<?php

use Fleetbase\FleetOps\Http\Controllers\Api\v1\VehicleController;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVehicleRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Models\Category;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetOpsApiVehicleCrudControllerProbe extends VehicleController
{
    public ?FleetOpsApiVehicleCrudFake $vehicle = null;
    public ?FleetOpsApiDriverCrudFake $driver   = null;
    public array $createdVehicles               = [];
    public array $relationLookups               = [];
    public array $unresolvable                  = [];
    public mixed $queryResults                  = null;
    public bool $vehicleNotFound                = false;
    public bool $driverNotFound                 = false;

    public function inputForTest(Request $request): array
    {
        return $this->vehicleInputFromRequest($request);
    }

    /**
     * Stand in for the company-scoped public-id lookup.
     *
     * Anything listed in `$unresolvable` behaves as a cross-company or missing
     * identifier does in production: the lookup finds nothing inside the
     * caller's company and raises.
     */
    protected function resolveUuid(string $modelClass, ?string $id, ?string $companyUuid = null): ?string
    {
        if (empty($id)) {
            return null;
        }

        $this->relationLookups[] = [$modelClass, $id];

        if (in_array($id, $this->unresolvable, true)) {
            throw (new ModelNotFoundException())->setModel($modelClass, $id);
        }

        return strtolower(class_basename($modelClass)) . '-uuid';
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
        ->and($controller->relationLookups)->toBe([
            [Vendor::class, 'vendor-public'],
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
            'vendor_uuid' => 'vendor-uuid',
        ])
        // A partial update must not carry an unrequested `online` default: the
        // absent key means "leave it alone", not "take the vehicle offline".
        ->and($filledInput)->not->toHaveKey('online')
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

test('api vehicle controller keeps the odometer in the input it builds from a request', function () {
    // Regression: `vehicleInputFromRequest` omitted odometer, so PUT
    // /v1/vehicles/{id} answered 200 with a correct-looking body and discarded
    // the reading. Recording mileage is the most common write a driver app
    // makes against a vehicle, and a silent no-op is the worst possible answer.
    $controller = new FleetOpsApiVehicleCrudControllerProbe();

    $input = $controller->inputForTest(new Request([
        'odometer'      => 211098,
        'odometer_unit' => 'km',
        'plate_number'  => 'SG-1234',
    ]));

    expect($input)->toHaveKey('odometer')
        ->and($input['odometer'])->toBe(211098)
        ->and($input['odometer_unit'])->toBe('km')
        ->and($input['plate_number'])->toBe('SG-1234');
});

test('api vehicle controller input still drops fields that are not part of the contract', function () {
    // The projection is an allowlist and must stay one — adding odometer must
    // not turn it into "whatever the caller sent".
    $controller = new FleetOpsApiVehicleCrudControllerProbe();

    $input = $controller->inputForTest(new Request([
        'odometer'     => 1,
        'company_uuid' => 'someone-elses-company',
        'uuid'         => 'forged',
    ]));

    expect($input)->toHaveKey('odometer')
        ->and($input)->not->toHaveKey('company_uuid')
        ->and($input)->not->toHaveKey('uuid');
});

test('api vehicle controller accepts every safe vehicle field the model exposes', function () {
    // Data-driven rather than one test per column: the point is parity between
    // what the model can store and what the public contract will accept, so the
    // assertion is over the whole set.
    session(['company' => 'company-uuid']);

    $payload = [
        // Identity and description
        'internal_id'  => 'VEH-9001', 'name' => 'Depot Van', 'description' => 'City route van',
        'make'         => 'Ford', 'model' => 'Transit', 'model_type' => 'Custom', 'year' => 2024,
        'trim'         => 'Trend', 'color' => 'White', 'type' => 'van', 'class' => 'N1',
        'plate_number' => 'SG-9001', 'vin' => '1FTBW3XG8NKA00001', 'serial_number' => 'SER-9001',
        'call_sign'    => 'DEPOT-1', 'fuel_card_number' => 'FC-9001',
        // Measurement and operation
        'odometer'           => 41000, 'odometer_unit' => 'km', 'odometer_at_purchase' => 12,
        'measurement_system' => 'metric', 'fuel_type' => 'diesel', 'fuel_volume_unit' => 'l',
        'online'             => true, 'status' => 'available',
        // Body, capacity and dimensions
        'transmission'     => 'automatic', 'body_type' => 'panel_van', 'body_sub_type' => 'lwb',
        'usage_type'       => 'commercial', 'ownership_type' => 'owned', 'cargo_volume' => 11.5,
        'passenger_volume' => 3.2, 'interior_volume' => 14.7, 'weight' => 2100.5, 'width' => 2.06,
        'length'           => 5.98, 'height' => 2.54, 'towing_capacity' => 2500, 'payload_capacity' => 1400,
        'seating_capacity' => 3, 'ground_clearance' => 0.18, 'bed_length' => 3.4, 'fuel_capacity' => 70,
        // Lifecycle and financing
        'financing_status'                     => 'financed', 'loan_number_of_payments' => 48,
        'loan_first_payment'                   => '2026-01-15', 'loan_amount' => 32000, 'currency' => 'SGD',
        'estimated_service_life_distance_unit' => 'km', 'estimated_service_life_distance' => 400000,
        'estimated_service_life_months'        => 96, 'insurance_value' => 41000, 'depreciation_rate' => 12.5,
        'current_value'                        => 38000, 'acquisition_cost' => 52000,
        'purchased_at'                         => '2026-01-02', 'lease_expires_at' => '2029-01-02',
        // Regulatory and engine specifications
        'emission_standard'   => 'euro6', 'dpf_equipped' => true, 'scr_equipped' => false,
        'gvwr'                => 3500, 'gcwr' => 6000, 'engine_number' => 'ENG-9001', 'engine_model' => 'EcoBlue',
        'engine_make'         => 'Ford', 'engine_family' => 'Puma', 'engine_configuration' => 'inline',
        'engine_displacement' => 2.0, 'engine_size' => 1995, 'horsepower' => 168,
        'horsepower_rpm'      => 3500, 'torque' => 405, 'torque_rpm' => 1750,
        'number_of_cylinders' => 4, 'cylinder_arrangement' => 'I4',
        // Structured and descriptive
        'specs' => ['doors' => 4], 'details' => ['liftgate' => true], 'notes' => 'City pool',
        'meta'  => ['depot' => 'north'],
        // Orchestrator
        'skills'                   => ['tail_lift'], 'payload_capacity_volume' => 11.25,
        'payload_capacity_pallets' => 6, 'payload_capacity_parcels' => 320, 'max_tasks' => 40,
        'time_window_start'        => '08:00', 'time_window_end' => '18:00', 'return_to_depot' => true,
    ];

    $controller = new FleetOpsApiVehicleCrudControllerProbe();
    $input      = $controller->inputForTest(new Request($payload));

    $missing = array_values(array_diff(array_keys($payload), array_keys($input)));

    expect($missing)->toBe([])
        ->and($input)->toMatchArray($payload);
});

test('api vehicle controller resolves every public relationship input to a scoped uuid', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiVehicleCrudControllerProbe();
    $input      = $controller->inputForTest(new Request([
        'vendor'   => 'vendor_abc',
        'category' => 'category_abc',
        'warranty' => 'warranty_abc',
        'photo'    => 'file_abc',
    ]));

    expect($input)->toMatchArray([
        'vendor_uuid'   => 'vendor-uuid',
        'category_uuid' => 'category-uuid',
        'warranty_uuid' => 'warranty-uuid',
        'photo_uuid'    => 'file-uuid',
    ])
        ->and($controller->relationLookups)->toBe([
            [Vendor::class, 'vendor_abc'],
            [Category::class, 'category_abc'],
            [Warranty::class, 'warranty_abc'],
            [Fleetbase\Models\File::class, 'file_abc'],
        ]);
});

test('api vehicle controller clears a relationship when the input is sent empty', function () {
    session(['company' => 'company-uuid']);

    $controller = new FleetOpsApiVehicleCrudControllerProbe();
    $input      = $controller->inputForTest(fleetopsUpdateVehicleRequest(['vendor' => null]));

    expect($input)->toHaveKey('vendor_uuid')
        ->and($input['vendor_uuid'])->toBeNull()
        ->and($controller->relationLookups)->toBe([]);
});

test('api vehicle controller rejects a relationship that belongs to another company', function () {
    session(['company' => 'company-uuid']);

    $controller               = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->unresolvable = ['vendor_other_company'];

    $created = $controller->create(fleetopsCreateVehicleRequest([
        'make'   => 'Ford',
        'vendor' => 'vendor_other_company',
    ]));

    $controller               = new FleetOpsApiVehicleCrudControllerProbe();
    $controller->vehicle      = new FleetOpsApiVehicleCrudFake();
    $controller->unresolvable = ['vendor_other_company'];

    $updated = $controller->update('vehicle-public', fleetopsUpdateVehicleRequest([
        'vendor' => 'vendor_other_company',
    ]));

    // A cross-company identifier is answered exactly as a missing one, so the
    // response cannot be used to discover what another organization holds.
    expect($created)->toBe([
        'json'   => ['error' => 'No vendor resource found for the identifier provided.'],
        'status' => 404,
    ])->and($updated)->toBe([
        'json'   => ['error' => 'No vendor resource found for the identifier provided.'],
        'status' => 404,
    ]);
});

test('api vehicle controller input still excludes server managed and internal columns', function () {
    $controller = new FleetOpsApiVehicleCrudControllerProbe();

    $input = $controller->inputForTest(new Request([
        'make'           => 'Ford',
        'company_uuid'   => 'someone-elses-company',
        'uuid'           => 'forged',
        '_key'           => 'forged',
        'public_id'      => 'vehicle_forged',
        'slug'           => 'forged',
        'vendor_uuid'    => 'forged',
        'category_uuid'  => 'forged',
        'warranty_uuid'  => 'forged',
        'photo_uuid'     => 'forged',
        'telematic_uuid' => 'forged',
        // Written by the VIN decoder and by telematics ingestion respectively;
        // a caller must not be able to overwrite either.
        'vin_data'       => ['manufacturer' => 'forged'],
        'telematics'     => ['speed' => 999],
        'avatar_url'     => 'forged',
    ]));

    expect(array_keys($input))->toBe(['make']);
});
