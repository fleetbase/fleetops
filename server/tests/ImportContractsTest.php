<?php

use Fleetbase\FleetOps\Imports\EquipmentImport;
use Fleetbase\FleetOps\Imports\MaintenanceImport;
use Fleetbase\FleetOps\Imports\MaintenanceScheduleImport;
use Fleetbase\FleetOps\Imports\OrdersImport;
use Fleetbase\FleetOps\Imports\PartImport;
use Fleetbase\FleetOps\Imports\VehicleExport as VehicleRowsImport;
use Fleetbase\FleetOps\Imports\WorkOrderImport;
use Illuminate\Support\Collection;

class FleetOpsEquipmentImportProbe extends EquipmentImport
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
}

class FleetOpsMaintenanceImportProbe extends MaintenanceImport
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
}

class FleetOpsMaintenanceScheduleImportProbe extends MaintenanceScheduleImport
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
}

class FleetOpsPartImportProbe extends PartImport
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
}

class FleetOpsWorkOrderImportProbe extends WorkOrderImport
{
    public array $created = [];

    protected function createFromImport(array $row): void
    {
        $this->created[] = $row;
    }
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
    FleetOpsEquipmentImportProbe::class,
    FleetOpsMaintenanceImportProbe::class,
    FleetOpsMaintenanceScheduleImportProbe::class,
    FleetOpsPartImportProbe::class,
    FleetOpsWorkOrderImportProbe::class,
]);

test('blank-row guarded imports skip empty spreadsheet rows before model creation', function () {
    $guardedImports = [
        'EquipmentImport.php',
        'MaintenanceImport.php',
        'MaintenanceScheduleImport.php',
        'PartImport.php',
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
            'EquipmentImport.php',
            'MaintenanceImport.php',
            'MaintenanceScheduleImport.php',
            'PartImport.php',
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
