<?php

use Fleetbase\FleetOps\Exports\EquipmentExport;
use Fleetbase\FleetOps\Exports\FuelReportExport;
use Fleetbase\FleetOps\Exports\PartExport;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\EquipmentController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\FuelReportController;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\PartController;
use Fleetbase\FleetOps\Imports\EquipmentImport;
use Fleetbase\FleetOps\Imports\FuelReportImport;
use Fleetbase\FleetOps\Imports\PartImport;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

if (!class_exists('Fleetbase\Http\Requests\ExportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ExportRequest extends \Illuminate\Http\Request {}');
}

if (!class_exists('Fleetbase\Http\Requests\ImportRequest', false)) {
    eval('namespace Fleetbase\Http\Requests; class ImportRequest extends \Illuminate\Http\Request {}');
}

class FleetOpsInternalExportRequestFake extends ExportRequest
{
    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }
}

class FleetOpsInternalImportRequestFake extends ImportRequest
{
    public array $resolvedFiles = [];

    public function array($key = null, $default = [])
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function resolveFilesFromIds(string $param = 'files')
    {
        return collect($this->resolvedFiles);
    }
}

class FleetOpsInternalEquipmentImportFake extends EquipmentImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalFuelReportImportFake extends FuelReportImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalPartImportFake extends PartImport
{
    public function __construct(int $imported)
    {
        $this->imported = $imported;
    }
}

class FleetOpsInternalEquipmentControllerProbe extends EquipmentController
{
    public array $downloads = [];
    public array $imports   = [];
    public array $imported  = [2, 3];
    public bool $failImport = false;

    protected function downloadExport(EquipmentExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected function createImport(): EquipmentImport
    {
        return new FleetOpsInternalEquipmentImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(EquipmentImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid equipment import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }
}

class FleetOpsInternalFuelReportControllerProbe extends FuelReportController
{
    public array $downloads        = [];
    public array $imports          = [];
    public array $syncedFieldSets  = [];
    public array $imported         = [4, 5];
    public bool $failImport        = false;

    protected function downloadExport(FuelReportExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected function createImport(): FuelReportImport
    {
        return new FleetOpsInternalFuelReportImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(FuelReportImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid fuel report import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }
}

class FleetOpsInternalPartControllerProbe extends PartController
{
    public array $downloads = [];
    public array $imports   = [];
    public array $imported  = [6, 7];
    public bool $failImport = false;

    protected function downloadExport(PartExport $export, string $fileName)
    {
        $this->downloads[] = [$export, $fileName];

        return ['download' => $fileName, 'headings' => $export->headings()];
    }

    protected function createImport(): PartImport
    {
        return new FleetOpsInternalPartImportFake(array_shift($this->imported) ?? 0);
    }

    protected function importFile(PartImport $import, string $path, string $disk): void
    {
        if ($this->failImport) {
            throw new RuntimeException('invalid part import');
        }

        $this->imports[] = [$import->imported, $path, $disk];
    }
}

class FleetOpsInternalFuelReportAfterSaveFake extends FuelReport
{
    public array $synced = [];

    public function syncCustomFieldValues(array $payload, array $options = []): array
    {
        $this->synced[] = [$payload, $options];

        return $payload;
    }
}

function fleetopsInternalExportRequest(array $input): FleetOpsInternalExportRequestFake
{
    return FleetOpsInternalExportRequestFake::create('/internal/export', 'POST', $input);
}

function fleetopsInternalImportRequest(array $files, array $input = []): FleetOpsInternalImportRequestFake
{
    $request                = FleetOpsInternalImportRequestFake::create('/internal/import', 'POST', $input);
    $request->resolvedFiles = array_map(fn (string $path) => (object) ['path' => $path], $files);

    return $request;
}

function fleetopsJsonPayload(JsonResponse $response): array
{
    return json_decode($response->getContent(), true);
}

function fleetopsExportSelections(object $export): array
{
    $property = new ReflectionProperty($export, 'selections');
    $property->setAccessible(true);

    return $property->getValue($export);
}

test('internal equipment fuel report and part controllers download selected exports', function () {
    $equipment = new FleetOpsInternalEquipmentControllerProbe();
    $fuel      = new FleetOpsInternalFuelReportControllerProbe();
    $part      = new FleetOpsInternalPartControllerProbe();

    $equipmentResponse = $equipment->export(fleetopsInternalExportRequest([
        'format'     => 'csv',
        'selections' => ['equipment-a', 'equipment-b'],
    ]));
    $fuelResponse = $fuel->export(fleetopsInternalExportRequest([
        'format'     => 'xlsx',
        'selections' => ['fuel-a'],
    ]));
    $partResponse = $part->export(fleetopsInternalExportRequest([
        'format'     => 'xls',
        'selections' => ['part-a', 'part-b'],
    ]));

    expect($equipmentResponse['download'])->toMatch('/^equipment-[0-9-]+\\.csv$/')
        ->and(fleetopsExportSelections($equipment->downloads[0][0]))->toBe(['equipment-a', 'equipment-b'])
        ->and($equipmentResponse['headings'])->toContain('Serial Number')
        ->and($fuelResponse['download'])->toMatch('/^fuel-report-[0-9-]+\\.xlsx$/')
        ->and(fleetopsExportSelections($fuel->downloads[0][0]))->toBe(['fuel-a'])
        ->and($fuelResponse['headings'])->toContain('Odometer')
        ->and($partResponse['download'])->toMatch('/^parts-[0-9-]+\\.xls$/')
        ->and(fleetopsExportSelections($part->downloads[0][0]))->toBe(['part-a', 'part-b'])
        ->and($partResponse['headings'])->toContain('Part Number');
});

test('internal equipment fuel report and part controllers import files and report totals', function () {
    $equipment = new FleetOpsInternalEquipmentControllerProbe();
    $fuel      = new FleetOpsInternalFuelReportControllerProbe();
    $part      = new FleetOpsInternalPartControllerProbe();

    $equipmentResponse = $equipment->import(fleetopsInternalImportRequest(['equipment-a.csv', 'equipment-b.csv'], ['disk' => 'imports']));
    $fuelResponse      = $fuel->import(fleetopsInternalImportRequest(['fuel-a.csv', 'fuel-b.csv'], ['disk' => 's3']));
    $partResponse      = $part->import(fleetopsInternalImportRequest(['part-a.csv', 'part-b.csv'], ['disk' => 'local']));

    expect(fleetopsJsonPayload($equipmentResponse))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 5])
        ->and($equipment->imports)->toBe([[2, 'equipment-a.csv', 'imports'], [3, 'equipment-b.csv', 'imports']])
        ->and(fleetopsJsonPayload($fuelResponse))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 9])
        ->and($fuel->imports)->toBe([[4, 'fuel-a.csv', 's3'], [5, 'fuel-b.csv', 's3']])
        ->and(fleetopsJsonPayload($partResponse))->toBe(['status' => 'ok', 'message' => 'Import completed', 'imported' => 13])
        ->and($part->imports)->toBe([[6, 'part-a.csv', 'local'], [7, 'part-b.csv', 'local']]);
});

test('internal import controllers return controller specific invalid file errors', function () {
    $equipment             = new FleetOpsInternalEquipmentControllerProbe();
    $equipment->failImport = true;
    $fuel                  = new FleetOpsInternalFuelReportControllerProbe();
    $fuel->failImport      = true;
    $part                  = new FleetOpsInternalPartControllerProbe();
    $part->failImport      = true;

    expect(fleetopsJsonPayload($equipment->import(fleetopsInternalImportRequest(['bad-equipment.csv']))))->toBe([
        'error' => 'Invalid file, unable to process.',
    ])
        ->and(fleetopsJsonPayload($fuel->import(fleetopsInternalImportRequest(['bad-fuel.csv']))))->toBe([
            'error' => 'Invalid file, unable to proccess.',
        ])
        ->and(fleetopsJsonPayload($part->import(fleetopsInternalImportRequest(['bad-part.csv']))))->toBe([
            'error' => 'Invalid file, unable to process.',
        ]);
});

test('internal fuel report controller syncs custom fields after save when provided', function () {
    $controller = new FleetOpsInternalFuelReportControllerProbe();
    $fuelReport = new FleetOpsInternalFuelReportAfterSaveFake();

    $controller->afterSave(new Request([
        'fuel_report' => [
            'custom_field_values' => [
                ['key' => 'receipt_number', 'value' => 'R-100'],
            ],
        ],
    ]), $fuelReport);
    $controller->afterSave(new Request(['fuel_report' => ['custom_field_values' => []]]), $fuelReport);

    expect($fuelReport->synced)->toBe([
        [
            [
                ['key' => 'receipt_number', 'value' => 'R-100'],
            ],
            [],
        ],
    ]);
});
