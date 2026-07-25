<?php

use Fleetbase\FleetOps\Imports\ContactImport;
use Fleetbase\FleetOps\Imports\DriverImport;
use Fleetbase\FleetOps\Imports\EquipmentImport;
use Fleetbase\FleetOps\Imports\FleetImport;
use Fleetbase\FleetOps\Imports\FuelReportImport;
use Fleetbase\FleetOps\Imports\IssueImport;
use Fleetbase\FleetOps\Imports\MaintenanceImport;
use Fleetbase\FleetOps\Imports\MaintenanceScheduleImport;
use Fleetbase\FleetOps\Imports\OrdersImport;
use Fleetbase\FleetOps\Imports\PartImport;
use Fleetbase\FleetOps\Imports\PlaceImport;
use Fleetbase\FleetOps\Imports\VehicleExport as VehicleRowsImport;
use Fleetbase\FleetOps\Imports\VehicleImport;
use Fleetbase\FleetOps\Imports\VendorImport;
use Fleetbase\FleetOps\Imports\WorkOrderImport;
use Illuminate\Support\Collection;

trait FleetOpsImportProbeRecorder
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
}

class FleetOpsContactImportProbe extends ContactImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsDriverImportProbe extends DriverImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsEquipmentImportProbe extends EquipmentImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsFleetImportProbe extends FleetImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsFuelReportImportProbe extends FuelReportImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsIssueImportProbe extends IssueImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsMaintenanceImportProbe extends MaintenanceImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsMaintenanceScheduleImportProbe extends MaintenanceScheduleImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsPartImportProbe extends PartImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsPlaceImportProbe extends PlaceImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsVehicleImportProbe extends VehicleImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsVendorImportProbe extends VendorImport
{
    use FleetOpsImportProbeRecorder;
}

class FleetOpsWorkOrderImportProbe extends WorkOrderImport
{
    use FleetOpsImportProbeRecorder;
}

test('pass through import adapters return the provided row collection', function () {
    $rows = new Collection([
        ['id' => 'row-1'],
        ['id' => 'row-2'],
    ]);

    expect((new OrdersImport())->collection($rows))->toBe($rows)
        ->and((new VehicleRowsImport())->collection($rows))->toBe($rows);
});

test('model backed import adapters create non empty rows and increment counters', function (string $class) {
    $import = new $class();

    $import->collection(new Collection([
        new Collection(['name' => 'Valid row', 'empty' => null]),
        new Collection(['name' => null, 'empty' => null]),
        ['code' => 'array-row'],
    ]));

    expect($import->imported)->toBe(2)
        ->and($import->created)->toBe([
            ['name' => 'Valid row'],
            ['code' => 'array-row'],
        ]);
})->with([
    FleetOpsContactImportProbe::class,
    FleetOpsDriverImportProbe::class,
    FleetOpsEquipmentImportProbe::class,
    FleetOpsFleetImportProbe::class,
    FleetOpsFuelReportImportProbe::class,
    FleetOpsIssueImportProbe::class,
    FleetOpsMaintenanceImportProbe::class,
    FleetOpsMaintenanceScheduleImportProbe::class,
    FleetOpsPartImportProbe::class,
    FleetOpsPlaceImportProbe::class,
    FleetOpsVehicleImportProbe::class,
    FleetOpsVendorImportProbe::class,
    FleetOpsWorkOrderImportProbe::class,
]);

test('blank-row guarded imports skip empty spreadsheet rows before model creation', function () {
    $guardedImports = [
        'ContactImport.php',
        'DriverImport.php',
        'EquipmentImport.php',
        'FleetImport.php',
        'FuelReportImport.php',
        'IssueImport.php',
        'MaintenanceImport.php',
        'MaintenanceScheduleImport.php',
        'PartImport.php',
        'PlaceImport.php',
        'VehicleImport.php',
        'VendorImport.php',
        'WorkOrderImport.php',
    ];

    foreach ($guardedImports as $file) {
        $source = file_get_contents(dirname(__DIR__) . '/src/Imports/' . $file);

        expect($source)
            ->toContain('if (empty($row))')
            ->toContain('continue;')
            ->toContain('$this->imported++');
    }
});

test('all model-backed import adapters maintain imported row counters', function () {
    $importsPath = dirname(__DIR__) . '/src/Imports';

    foreach (glob($importsPath . '/*Import.php') as $path) {
        if (!in_array(basename($path), [
            'ContactImport.php',
            'DriverImport.php',
            'EquipmentImport.php',
            'FleetImport.php',
            'FuelReportImport.php',
            'IssueImport.php',
            'MaintenanceImport.php',
            'MaintenanceScheduleImport.php',
            'PartImport.php',
            'PlaceImport.php',
            'VehicleImport.php',
            'VendorImport.php',
            'WorkOrderImport.php',
        ], true)) {
            continue;
        }

        $source = file_get_contents($path);

        expect($source)
            ->toContain('public int $imported = 0;')
            ->toContain('$this->imported++')
            ->toContain('$this->createFromImport($row);');
    }
});
