<?php

/**
 * Covers the real Excel download/import seam bodies on the internal
 * controllers. Feature tests override these seams to avoid touching the
 * spreadsheet stack, which leaves the one-line bodies themselves unexercised;
 * invoking them against a faked Excel facade closes that gap.
 */
function fleetopsExcelSeamFake(): object
{
    $excel = new class {
        public array $downloads = [];
        public array $imports   = [];

        public function download($export, $fileName)
        {
            $this->downloads[] = [$export::class, $fileName];

            return 'excel-download';
        }

        public function import($import, $path, $disk = null)
        {
            $this->imports[] = [$import::class, $path, $disk];

            return null;
        }

        public function __call($method, $arguments)
        {
            return null;
        }
    };

    app()->instance('excel', $excel);
    Maatwebsite\Excel\Facades\Excel::clearResolvedInstance('excel');

    return $excel;
}

test('download export seams stream through the excel facade', function (string $controllerClass, string $exportClass) {
    $excel = fleetopsExcelSeamFake();

    $reflection = new ReflectionMethod($controllerClass, 'downloadExport');
    $reflection->setAccessible(true);
    $export = new $exportClass([]);
    $target = $reflection->isStatic() ? null : (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();

    expect($reflection->invoke($target, $export, 'export.xlsx'))->toBe('excel-download')
        ->and($excel->downloads)->toBe([[$exportClass, 'export.xlsx']]);
})->with([
    'equipment'     => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\EquipmentController::class, Fleetbase\FleetOps\Exports\EquipmentExport::class],
    'fleets'        => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController::class, Fleetbase\FleetOps\Exports\FleetExport::class],
    'fuel reports'  => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelReportController::class, Fleetbase\FleetOps\Exports\FuelReportExport::class],
    'parts'         => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\PartController::class, Fleetbase\FleetOps\Exports\PartExport::class],
    'sensors'       => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\SensorController::class, Fleetbase\FleetOps\Exports\SensorExport::class],
    'service areas' => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\ServiceAreaController::class, Fleetbase\FleetOps\Exports\ServiceAreaExport::class],
]);

test('import seams hand the file to the excel facade with its disk', function (string $controllerClass, string $importClass) {
    $excel = fleetopsExcelSeamFake();

    $reflection = new ReflectionMethod($controllerClass, 'importFile');
    $reflection->setAccessible(true);
    $import = new $importClass();
    $target = $reflection->isStatic() ? null : (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();

    $reflection->invoke($target, $import, 'imports/company/file.xlsx', 'local');

    expect($excel->imports)->toBe([[$importClass, 'imports/company/file.xlsx', 'local']]);
})->with([
    'equipment'    => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\EquipmentController::class, Fleetbase\FleetOps\Imports\EquipmentImport::class],
    'fleets'       => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController::class, Fleetbase\FleetOps\Imports\FleetImport::class],
    'fuel reports' => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelReportController::class, Fleetbase\FleetOps\Imports\FuelReportImport::class],
    'parts'        => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\PartController::class, Fleetbase\FleetOps\Imports\PartImport::class],
]);

test('import factory seams build their importer', function (string $controllerClass, string $importClass) {
    $reflection = new ReflectionMethod($controllerClass, 'createImport');
    $reflection->setAccessible(true);
    $target = $reflection->isStatic() ? null : (new ReflectionClass($controllerClass))->newInstanceWithoutConstructor();

    expect($reflection->invoke($target))->toBeInstanceOf($importClass);
})->with([
    'equipment'    => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\EquipmentController::class, Fleetbase\FleetOps\Imports\EquipmentImport::class],
    'fleets'       => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FleetController::class, Fleetbase\FleetOps\Imports\FleetImport::class],
    'fuel reports' => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelReportController::class, Fleetbase\FleetOps\Imports\FuelReportImport::class],
    'parts'        => [Fleetbase\FleetOps\Http\Controllers\Internal\v1\PartController::class, Fleetbase\FleetOps\Imports\PartImport::class],
]);
