<?php

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!function_exists('Fleetbase\Support\session')) {
    eval('namespace Fleetbase\Support; function session($key = null, $default = null) { if ($key === null) { return new class { public function has($k) { return \session($k) !== null; } public function get($k, $d = null) { return \session($k, $d); } }; } return \session($key, $default); }');
}

if (!function_exists('Fleetbase\Support\auth')) {
    eval('namespace Fleetbase\Support; function auth() { return new class { public function user() { return null; } public function id() { return null; } }; }');
}

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\DeviceController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;

/**
 * Covers the internal DeviceController export endpoint with a stand-in
 * export request and excel fake, the query-record filters for attachment
 * state and vehicle scoping, and the device and vehicle resolver seams
 * against SQLite.
 */
class FleetOpsDeviceControllerProbe extends DeviceController
{
    public function callHelper(string $method, ...$arguments): mixed
    {
        return $this->{$method}(...$arguments);
    }
}

function fleetopsDeviceExportBoot(): SQLiteConnection
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
    Illuminate\Support\Facades\DB::clearResolvedInstance('db');

    app()->instance('excel', new class {
        public array $downloads = [];

        public function download($export, $fileName, $writerType = null, array $headers = [])
        {
            $this->downloads[] = $fileName;

            return response()->json(['download' => $fileName]);
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    });
    $GLOBALS['fleetopsDeviceExcelFake'] = app('excel');

    $schema = $connection->getSchemaBuilder();
    $tables = [
        'devices'  => ['uuid', 'public_id', 'company_uuid', 'telematic_uuid', 'attachable_uuid', 'attachable_type', 'name', 'model', 'serial_number', 'manufacturer', 'device_id', 'internal_id', 'imei', 'status', '_key'],
        'vehicles' => ['uuid', 'public_id', 'company_uuid', 'name', 'plate_number', 'location', 'online'],
        'sensors'  => ['uuid', 'public_id', 'company_uuid', 'device_uuid', 'name', 'type'],
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

test('export downloads a device spreadsheet through the excel seam', function () {
    fleetopsDeviceExportBoot();

    $request  = ExportRequest::create('/int/v1/devices/export', 'GET', ['format' => 'csv', 'selections' => ['device-1']]);
    $response = (new DeviceController())->export($request);

    expect($response->getData(true)['download'])->toContain('.csv')
        ->and($GLOBALS['fleetopsDeviceExcelFake']->downloads)->toHaveCount(1);
});

test('query record filters attachment state and vehicle scoping', function () {
    $connection = fleetopsDeviceExportBoot();
    $connection->table('vehicles')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'vehicle_devflt1', 'company_uuid' => 'company-1', 'name' => 'Van']);
    $connection->table('devices')->insert([
        ['uuid' => 'device-1', 'public_id' => 'device_devflt1', 'company_uuid' => 'company-1', 'attachable_uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Tracker A'],
        ['uuid' => 'device-2', 'public_id' => 'device_devflt2', 'company_uuid' => 'company-1', 'attachable_uuid' => null, 'name' => 'Tracker B'],
    ]);

    $attached = Device::query();
    DeviceController::onQueryRecord($attached, Request::create('/x', 'GET', ['attachment_state' => 'attached']));
    expect($attached->count())->toBe(1);

    $unattached = Device::query();
    DeviceController::onQueryRecord($unattached, Request::create('/x', 'GET', ['attachment_state' => 'unattached']));
    expect($unattached->count())->toBe(1);

    $unknownState = Device::query();
    DeviceController::onQueryRecord($unknownState, Request::create('/x', 'GET', ['attachment_state' => 'anything']));
    expect($unknownState->count())->toBe(2);

    // Vehicle filters match attachable uuids directly and by public id
    $byUuid = Device::query();
    DeviceController::onQueryRecord($byUuid, Request::create('/x', 'GET', ['vehicle' => '11111111-1111-4111-8111-111111111111']));
    expect($byUuid->count())->toBe(1);

    $byPublicId = Device::query();
    DeviceController::onQueryRecord($byPublicId, Request::create('/x', 'GET', ['vehicle' => 'vehicle_devflt1']));
    expect($byPublicId->count())->toBe(1);
});

test('device and vehicle resolvers match uuid and public id inputs', function () {
    $connection = fleetopsDeviceExportBoot();
    $connection->table('vehicles')->insert(['uuid' => '11111111-1111-4111-8111-111111111111', 'public_id' => 'vehicle_devres1', 'company_uuid' => 'company-1', 'name' => 'Van']);
    $connection->table('devices')->insert(['uuid' => '22222222-2222-4222-8222-222222222222', 'public_id' => 'device_devres1', 'company_uuid' => 'company-1', 'name' => 'Tracker']);

    $probe = new FleetOpsDeviceControllerProbe();

    expect($probe->callHelper('resolveDevice', 'device_devres1')?->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($probe->callHelper('resolveDevice', '22222222-2222-4222-8222-222222222222')?->uuid)->toBe('22222222-2222-4222-8222-222222222222')
        ->and($probe->callHelper('resolveDevice', 'device_missing99'))->toBeNull()
        ->and($probe->callHelper('resolveVehicle', 'vehicle_devres1')?->uuid)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($probe->callHelper('resolveVehicle', null))->toBeNull();
});
