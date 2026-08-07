<?php

use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\VehicleController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Covers the internal VehicleController's database-backed endpoints and the
 * real bodies of its lookup helpers: driver assignment syncing, assigned and
 * unassigned order management, vehicle/driver/device lookups, status and
 * avatar options, and the excel export/import endpoints.
 */
if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

if (!Request::hasMacro('resolveFilesFromIds')) {
    Request::macro('resolveFilesFromIds', fn () => FleetOpsInternalVehicleEndpointsState::$files);
}

class FleetOpsInternalVehicleEndpointsState
{
    public static array $files = [];
}

class FleetOpsInternalVehicleEndpointsExcelFake
{
    public array $downloads  = [];
    public array $imports    = [];
    public bool $importFails = false;

    public function download($export, string $fileName): string
    {
        $this->downloads[] = [$export, $fileName];

        return 'downloaded:' . $fileName;
    }

    public function import($import, $path, $disk = null): bool
    {
        if ($this->importFails) {
            throw new RuntimeException('corrupt file');
        }

        $this->imports[] = [$import, $path, $disk];
        $import->imported++;

        return true;
    }
}

class FleetOpsInternalVehicleEndpointsVehicleFake extends Vehicle
{
    protected $guarded         = [];
    public $exists             = true;
    public array $assignments  = [];

    public function assignDriver($driver, bool $save = true): self
    {
        $this->assignments[] = ['assign', $driver->uuid];

        return $this;
    }

    public function unassignDriver(bool $save = true): self
    {
        $this->assignments[] = ['unassign'];

        return $this;
    }
}

class FleetOpsInternalVehicleEndpointsProbe extends VehicleController
{
    public function callProtected(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(VehicleController::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this, ...$arguments);
    }

    protected function activeOrderUuid(Vehicle $vehicle): ?string
    {
        return null;
    }
}

function fleetopsInternalVehicleEndpointsBoot(): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $resolver   = new ConnectionResolver(['default' => $connection, 'mysql' => $connection]);
    $resolver->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($resolver);
    app()->instance('db', new class($connection) {
        public function __construct(public SQLiteConnection $c)
        {
        }

        public function connection($name = null): SQLiteConnection
        {
            return $this->c;
        }

        public function __call($method, $arguments)
        {
            return $this->c->{$method}(...$arguments);
        }
    });
    app()->instance('request', Request::create('/int/v1/vehicles'));
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'vehicles'  => ['uuid', 'public_id', 'company_uuid', 'driver_uuid', 'status', 'plate_number', 'year', 'make', 'model'],
        'drivers'   => ['uuid', 'public_id', 'company_uuid', 'vehicle_uuid', 'user_uuid', 'current_job_uuid'],
        'users'     => ['uuid', 'public_id', 'company_uuid'],
        'devices'   => ['uuid', 'public_id', 'company_uuid', 'attachable_uuid', 'attachable_type'],
        'orders'    => ['uuid', 'public_id', 'company_uuid', 'vehicle_assigned_uuid', 'driver_assigned_uuid', 'payload_uuid', 'tracking_number_uuid', 'order_config_uuid', 'status', 'scheduled_at', 'dispatched_at'],
        'files'     => ['uuid', 'type', 'original_filename', 'company_uuid'],
        'positions' => ['uuid', 'company_uuid', 'subject_uuid', 'subject_type', 'order_uuid'],
    ];
    foreach ($tables as $table => $columns) {
        $schema->create($table, function ($blueprint) use ($columns) {
            $blueprint->increments('id');
            foreach ($columns as $column) {
                $blueprint->string($column)->nullable();
            }
            $blueprint->timestamps();
            $blueprint->timestamp('deleted_at')->nullable();
        });
    }

    session(['company' => 'company-1']);

    return $connection;
}

function fleetopsInternalVehicleEndpointsSeedVehicle(SQLiteConnection $connection, string $uuid = 'vehicle-1'): void
{
    $connection->table('vehicles')->insert([
        'uuid'         => $uuid,
        'public_id'    => 'vehicle_' . $uuid,
        'company_uuid' => 'company-1',
    ]);
}

function fleetopsInternalVehicleEndpointsExcel(): FleetOpsInternalVehicleEndpointsExcelFake
{
    $fake = new FleetOpsInternalVehicleEndpointsExcelFake();
    app()->instance('excel', $fake);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    return $fake;
}

/*
|--------------------------------------------------------------------------
| Driver assignment syncing
|--------------------------------------------------------------------------
*/

test('sync driver assignment unassigns the driver when no identifier is given', function () {
    fleetopsInternalVehicleEndpointsBoot();
    $vehicle = new FleetOpsInternalVehicleEndpointsVehicleFake();

    (new FleetOpsInternalVehicleEndpointsProbe())->callProtected('syncDriverAssignment', [$vehicle, null]);

    expect($vehicle->assignments)->toBe([['unassign']]);
});

test('sync driver assignment assigns a matching company driver', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1']);
    $vehicle = new FleetOpsInternalVehicleEndpointsVehicleFake();

    (new FleetOpsInternalVehicleEndpointsProbe())->callProtected('syncDriverAssignment', [$vehicle, 'driver_test']);

    expect($vehicle->assignments)->toBe([['assign', 'driver-1']])
        ->and($vehicle->getRelation('driver')->uuid)->toBe('driver-1');
});

test('sync driver assignment ignores identifiers with no matching driver', function () {
    fleetopsInternalVehicleEndpointsBoot();
    $vehicle = new FleetOpsInternalVehicleEndpointsVehicleFake();

    (new FleetOpsInternalVehicleEndpointsProbe())->callProtected('syncDriverAssignment', [$vehicle, 'driver_unknown']);

    expect($vehicle->assignments)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Order assignment endpoints
|--------------------------------------------------------------------------
*/

test('assigned orders endpoint lists orders assigned to the vehicle', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    fleetopsInternalVehicleEndpointsSeedVehicle($connection);
    $connection->table('orders')->insert([
        'uuid'                  => 'order-1',
        'public_id'             => 'order_test',
        'company_uuid'          => 'company-1',
        'vehicle_assigned_uuid' => 'vehicle-1',
        'status'                => 'created',
    ]);

    $response = (new FleetOpsInternalVehicleEndpointsProbe())->assignedOrders('vehicle-1');

    expect($response)->toBeInstanceOf(JsonResponse::class);
    $data = $response->getData(true);
    expect($data['status'])->toBe('ok')
        ->and($data['count'])->toBe(1)
        ->and($data['orders'][0]['id'])->toBe('order_test');
});

test('unassign orders endpoint errors when no assigned orders match', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    fleetopsInternalVehicleEndpointsSeedVehicle($connection);

    $request  = Request::create('/int/v1/vehicles/vehicle-1/unassign-orders', 'POST', ['orders' => ['order_missing']]);
    $response = (new FleetOpsInternalVehicleEndpointsProbe())->unassignOrders($request, 'vehicle-1');

    expect($response->getData(true))->toBe(['error' => 'No assigned orders were selected for this vehicle.']);
});

test('unassign orders endpoint removes the vehicle from selected orders', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    fleetopsInternalVehicleEndpointsSeedVehicle($connection);
    $connection->table('orders')->insert([
        'uuid'                  => 'order-1',
        'public_id'             => 'order_test',
        'company_uuid'          => 'company-1',
        'vehicle_assigned_uuid' => 'vehicle-1',
        'status'                => 'created',
    ]);

    $request  = Request::create('/int/v1/vehicles/vehicle-1/unassign-orders', 'POST', ['orders' => ['order-1']]);
    $response = (new FleetOpsInternalVehicleEndpointsProbe())->unassignOrders($request, 'vehicle-1');

    $data = $response->getData(true);
    expect($data['status'])->toBe('ok')
        ->and($data['count'])->toBe(1)
        ->and($connection->table('orders')->where('uuid', 'order-1')->value('vehicle_assigned_uuid'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Lookup helpers
|--------------------------------------------------------------------------
*/

test('vehicle and driver lookup helpers resolve records scoped to the company', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    fleetopsInternalVehicleEndpointsSeedVehicle($connection);
    $connection->table('users')->insert(['uuid' => 'user-1', 'company_uuid' => 'company-1']);
    $connection->table('drivers')->insert(['uuid' => 'driver-1', 'public_id' => 'driver_test', 'company_uuid' => 'company-1', 'user_uuid' => 'user-1']);
    $connection->table('devices')->insert(['uuid' => 'device-1', 'public_id' => 'device_test', 'company_uuid' => 'company-1']);

    $probe = new FleetOpsInternalVehicleEndpointsProbe();

    expect($probe->callProtected('findVehicle', ['vehicle-1']))->toBeInstanceOf(Vehicle::class)
        ->and($probe->callProtected('resolveVehicle', ['vehicle-1']))->toBeInstanceOf(Vehicle::class)
        ->and($probe->callProtected('resolveVehicle', ['missing']))->toBeNull()
        ->and($probe->callProtected('findDriver', ['driver_test']))->toBeInstanceOf(Driver::class)
        ->and($probe->callProtected('resolveDevice', ['device_test']))->toBeInstanceOf(Device::class)
        ->and($probe->callProtected('resolveDevice', ['missing']))->toBeNull()
        ->and($probe->callProtected('findDevice', ['device-1']))->toBeInstanceOf(Device::class);

    expect(fn () => $probe->callProtected('findVehicle', ['missing']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $probe->callProtected('findDriver', ['missing']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $probe->callProtected('findDevice', ['missing']))->toThrow(ModelNotFoundException::class);
});

/*
|--------------------------------------------------------------------------
| Options endpoints
|--------------------------------------------------------------------------
*/

test('statuses endpoint returns distinct non-empty company vehicle statuses', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    $connection->table('vehicles')->insert([
        ['uuid' => 'v1', 'company_uuid' => 'company-1', 'status' => 'active'],
        ['uuid' => 'v2', 'company_uuid' => 'company-1', 'status' => 'active'],
        ['uuid' => 'v3', 'company_uuid' => 'company-1', 'status' => 'maintenance'],
        ['uuid' => 'v4', 'company_uuid' => 'company-1', 'status' => null],
        ['uuid' => 'v5', 'company_uuid' => 'other-company', 'status' => 'inactive'],
    ]);

    $response = (new VehicleController())->statuses();

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and(collect($response->getData(true))->sort()->values()->all())->toBe(['active', 'maintenance']);
});

test('avatars endpoint merges custom company avatars with defaults', function () {
    $connection = fleetopsInternalVehicleEndpointsBoot();
    $connection->table('files')->insert([
        'uuid'              => 'file-1',
        'type'              => 'vehicle-avatar',
        'original_filename' => 'fleet-truck.svg',
        'company_uuid'      => 'company-1',
    ]);

    $response = (new VehicleController())->avatars();

    expect($response)->toBeInstanceOf(JsonResponse::class);
    $options = $response->getData(true);
    expect($options)->toHaveKey('Custom: fleet-truck');
});

/*
|--------------------------------------------------------------------------
| Export and import endpoints
|--------------------------------------------------------------------------
*/

test('export endpoint streams a vehicle export download', function () {
    $excel = fleetopsInternalVehicleEndpointsExcel();

    $request  = ExportRequest::create('/int/v1/vehicles/export', 'POST', ['format' => 'csv', 'selections' => ['vehicle-1']]);
    $response = VehicleController::export($request);

    expect($response)->toStartWith('downloaded:vehicles-')
        ->and($response)->toEndWith('.csv')
        ->and($excel->downloads[0][0])->toBeInstanceOf(VehicleExport::class);
});

test('import endpoint imports resolved files and reports failures', function () {
    $excel                                        = fleetopsInternalVehicleEndpointsExcel();
    FleetOpsInternalVehicleEndpointsState::$files = [
        (object) ['path' => 'uploads/vehicles-a.xlsx'],
    ];

    $request  = ImportRequest::create('/int/v1/vehicles/import', 'POST', ['disk' => 'local']);
    $response = (new VehicleController())->import($request);

    expect($response->getData(true))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 1])
        ->and($excel->imports)->toHaveCount(1);

    $excel->importFails = true;
    $failure            = (new VehicleController())->import($request);

    expect($failure->getData(true))->toBe(['error' => 'Invalid file, unable to proccess.']);
});
