<?php

use Fleetbase\FleetOps\Exports\FleetExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController;
use Fleetbase\FleetOps\Http\Requests\Internal\FleetActionRequest;
use Fleetbase\FleetOps\Imports\FleetImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalFleetActionRequestFake extends FleetActionRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsInternalFleetExportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsInternalFleetImportRequestFake extends ImportRequest
{
    public array $resolvedFiles = [];

    public function resolveFilesFromIds(string $param = 'files')
    {
        return collect($this->resolvedFiles);
    }
}

class FleetOpsInternalFleetImportFake extends FleetImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalFleetContractsControllerProbe extends FleetController
{
    public static array $downloads          = [];
    public static array $jsonResponses      = [];
    public static array $invalidated        = [];
    public static array $createdDrivers     = [];
    public static array $createdVehicles    = [];
    public static array $deletedDrivers     = [];
    public static array $deletedVehicles    = [];
    public static bool $driverExists        = false;
    public static bool $vehicleExists       = false;
    public static mixed $driverDeleteCount  = 0;
    public static mixed $vehicleDeleteCount = 0;

    public array $imports   = [];
    public array $imported  = [2, 3];
    public bool $failImport = false;

    public static function resetProbe(): void
    {
        static::$downloads          = [];
        static::$jsonResponses      = [];
        static::$invalidated        = [];
        static::$createdDrivers     = [];
        static::$createdVehicles    = [];
        static::$deletedDrivers     = [];
        static::$deletedVehicles    = [];
        static::$driverExists       = false;
        static::$vehicleExists      = false;
        static::$driverDeleteCount  = 0;
        static::$vehicleDeleteCount = 0;
    }

    protected static function downloadExport(FleetExport $export, string $fileName)
    {
        static::$downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected static function findFleetByUuid(string $uuid): ?Fleet
    {
        return tap(new Fleet(), fn (Fleet $fleet) => $fleet->setRawAttributes(['uuid' => $uuid], true));
    }

    protected static function findDriverByUuid(string $uuid): ?Driver
    {
        return tap(new Driver(), fn (Driver $driver) => $driver->setRawAttributes(['uuid' => $uuid], true));
    }

    protected static function findVehicleByUuid(string $uuid): ?Vehicle
    {
        return tap(new Vehicle(), fn (Vehicle $vehicle) => $vehicle->setRawAttributes(['uuid' => $uuid], true));
    }

    protected static function fleetDriverAssignmentExists(string $fleetUuid, string $driverUuid): bool
    {
        return static::$driverExists;
    }

    protected static function createFleetDriverAssignment(string $fleetUuid, string $driverUuid): FleetDriver
    {
        static::$createdDrivers[] = [$fleetUuid, $driverUuid];

        return new FleetDriver();
    }

    protected static function deleteFleetDriverAssignment(string $fleetUuid, string $driverUuid): mixed
    {
        static::$deletedDrivers[] = [$fleetUuid, $driverUuid];

        return static::$driverDeleteCount;
    }

    protected static function fleetVehicleAssignmentExists(string $fleetUuid, string $vehicleUuid): bool
    {
        return static::$vehicleExists;
    }

    protected static function createFleetVehicleAssignment(string $fleetUuid, string $vehicleUuid): FleetVehicle
    {
        static::$createdVehicles[] = [$fleetUuid, $vehicleUuid];

        return new FleetVehicle();
    }

    protected static function deleteFleetVehicleAssignment(string $fleetUuid, string $vehicleUuid): mixed
    {
        static::$deletedVehicles[] = [$fleetUuid, $vehicleUuid];

        return static::$vehicleDeleteCount;
    }

    protected static function invalidateOperationsMonitor(): void
    {
        static::$invalidated[] = 'operations-monitor';
    }

    protected function createImport(): FleetImport
    {
        return new FleetOpsInternalFleetImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(FleetImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid fleet import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }

    protected static function jsonResponse(array $payload)
    {
        static::$jsonResponses[] = $payload;

        return $payload;
    }
}

class FleetOpsInternalFleetQueryRecorder
{
    public array $calls = [];

    public function with($relation, $callback = null)
    {
        $this->calls[] = ['with', $relation];

        if (is_callable($callback)) {
            $callback($this);
        }

        return $this;
    }

    public function whereNotIn(string $column, array $values)
    {
        $this->calls[] = ['whereNotIn', $column, $values];

        return $this;
    }

    public function whereHas(string $relation, $callback = null)
    {
        $this->calls[] = ['whereHas', $relation];

        if (is_callable($callback)) {
            $callback($this);
        }

        return $this;
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->calls[] = ['where'];

        if (is_callable($column)) {
            $column($this);
        }

        return $this;
    }

    public function orWhereHas(string $relation)
    {
        $this->calls[] = ['orWhereHas', $relation];

        return $this;
    }
}

function fleetopsInternalFleetExportRequest(array $input): FleetOpsInternalFleetExportRequestFake
{
    return FleetOpsInternalFleetExportRequestFake::create('/internal/fleets/export', 'POST', $input);
}

function fleetopsInternalFleetImportRequest(array $files, array $input = []): FleetOpsInternalFleetImportRequestFake
{
    $request                = FleetOpsInternalFleetImportRequestFake::create('/internal/fleets/import', 'POST', $input);
    $request->resolvedFiles = array_map(fn (string $path) => (object) ['path' => $path], $files);

    return $request;
}

function fleetopsInternalFleetActionRequest(array $input): FleetOpsInternalFleetActionRequestFake
{
    return FleetOpsInternalFleetActionRequestFake::create('/internal/fleets/action', 'POST', $input);
}

function fleetopsInternalFleetExportSelections(object $export): array
{
    $property = new ReflectionProperty($export, 'selections');
    $property->setAccessible(true);

    return $property->getValue($export);
}

test('internal fleet controller downloads selected exports', function () {
    FleetOpsInternalFleetContractsControllerProbe::resetProbe();

    $response = FleetOpsInternalFleetContractsControllerProbe::export(fleetopsInternalFleetExportRequest([
        'format'     => 'csv',
        'selections' => ['fleet-a', 'fleet-b'],
    ]));

    expect($response['download'])->toMatch('/^fleets-[0-9-]+\\.csv$/')
        ->and($response['headings'])->toContain('Name')
        ->and(fleetopsInternalFleetExportSelections(FleetOpsInternalFleetContractsControllerProbe::$downloads[0][0]))->toBe(['fleet-a', 'fleet-b']);
});

test('internal fleet controller imports files and reports invalid files', function () {
    $controller = new FleetOpsInternalFleetContractsControllerProbe();

    expect($controller->import(fleetopsInternalFleetImportRequest(['fleets-a.csv', 'fleets-b.csv'], ['disk' => 'imports'])))->toBe([
        'status'   => 'ok',
        'message'  => 'Import completed',
        'imported' => 5,
    ])->and($controller->imports)->toBe([[2, 'fleets-a.csv', 'imports'], [3, 'fleets-b.csv', 'imports']]);

    $controller             = new FleetOpsInternalFleetContractsControllerProbe();
    $controller->failImport = true;

    expect(json_decode($controller->import(fleetopsInternalFleetImportRequest(['bad-fleets.csv']))->getContent(), true))->toBe([
        'error' => 'Invalid file, unable to proccess.',
    ]);
});

test('internal fleet controller assigns and removes drivers and vehicles', function () {
    FleetOpsInternalFleetContractsControllerProbe::resetProbe();

    expect(FleetOpsInternalFleetContractsControllerProbe::assignDriver(fleetopsInternalFleetActionRequest([
        'fleet'  => 'fleet-uuid',
        'driver' => 'driver-uuid',
    ])))->toBe([
        'status' => 'ok',
        'exists' => false,
        'added'  => true,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$createdDrivers)->toBe([
        ['fleet-uuid', 'driver-uuid'],
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$invalidated)->toBe(['operations-monitor']);

    FleetOpsInternalFleetContractsControllerProbe::$driverExists = true;

    expect(FleetOpsInternalFleetContractsControllerProbe::assignDriver(fleetopsInternalFleetActionRequest([
        'fleet'  => 'fleet-uuid',
        'driver' => 'driver-uuid',
    ])))->toBe([
        'status' => 'ok',
        'exists' => true,
        'added'  => false,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$createdDrivers)->toHaveCount(1);

    FleetOpsInternalFleetContractsControllerProbe::$driverDeleteCount = 2;

    expect(FleetOpsInternalFleetContractsControllerProbe::removeDriver(fleetopsInternalFleetActionRequest([
        'fleet'  => 'fleet-uuid',
        'driver' => 'driver-uuid',
    ])))->toBe([
        'status'  => 'ok',
        'deleted' => 2,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$deletedDrivers)->toBe([
        ['fleet-uuid', 'driver-uuid'],
    ]);

    FleetOpsInternalFleetContractsControllerProbe::$vehicleExists = false;

    expect(FleetOpsInternalFleetContractsControllerProbe::assignVehicle(fleetopsInternalFleetActionRequest([
        'fleet'   => 'fleet-uuid',
        'vehicle' => 'vehicle-uuid',
    ])))->toBe([
        'status' => 'ok',
        'exists' => false,
        'added'  => true,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$createdVehicles)->toBe([
        ['fleet-uuid', 'vehicle-uuid'],
    ]);

    FleetOpsInternalFleetContractsControllerProbe::$vehicleExists      = true;
    FleetOpsInternalFleetContractsControllerProbe::$vehicleDeleteCount = 4;

    expect(FleetOpsInternalFleetContractsControllerProbe::assignVehicle(fleetopsInternalFleetActionRequest([
        'fleet'   => 'fleet-uuid',
        'vehicle' => 'vehicle-uuid',
    ])))->toBe([
        'status' => 'ok',
        'exists' => true,
        'added'  => false,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::removeVehicle(fleetopsInternalFleetActionRequest([
        'fleet'   => 'fleet-uuid',
        'vehicle' => 'vehicle-uuid',
    ])))->toBe([
        'status'  => 'ok',
        'deleted' => 4,
    ])->and(FleetOpsInternalFleetContractsControllerProbe::$deletedVehicles)->toBe([
        ['fleet-uuid', 'vehicle-uuid'],
    ]);
});

test('internal fleet controller filters driver jobs by ids and required payload relations', function () {
    $uuidQuery = new FleetOpsInternalFleetQueryRecorder();

    FleetController::onQueryRecord($uuidQuery, new Request([
        'excludeDriverJobs' => [
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
        ],
    ]));

    $publicIdQuery = new FleetOpsInternalFleetQueryRecorder();

    FleetController::onQueryRecord($publicIdQuery, new Request([
        'excludeDriverJobs' => ['order_public_a', 'order_public_b'],
    ]));

    $emptyQuery = new FleetOpsInternalFleetQueryRecorder();
    FleetController::onQueryRecord($emptyQuery, new Request());

    expect($uuidQuery->calls)->toContain(['whereNotIn', 'uuid', [
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
    ]])
        ->and($uuidQuery->calls)->toContain(['whereHas', 'payload'])
        ->and($uuidQuery->calls)->toContain(['whereHas', 'trackingNumber'])
        ->and($uuidQuery->calls)->toContain(['whereHas', 'trackingStatuses'])
        ->and($publicIdQuery->calls)->toContain(['whereNotIn', 'public_id', ['order_public_a', 'order_public_b']])
        ->and($emptyQuery->calls)->toBe([]);
});
