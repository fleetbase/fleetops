<?php

use Fleetbase\FleetOps\Imports\OrdersImport;
use Fleetbase\FleetOps\Imports\VehicleExport as VehicleRowsImport;
use Illuminate\Support\Collection;

test('pass through import adapters return the provided row collection', function () {
    $rows = new Collection([
        ['id' => 'row-1'],
        ['id' => 'row-2'],
    ]);

    expect((new OrdersImport())->collection($rows))->toBe($rows)
        ->and((new VehicleRowsImport())->collection($rows))->toBe($rows);
});

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
        if (basename($path) === 'OrdersImport.php') {
            continue;
        }

        $source = file_get_contents($path);

        expect($source)
            ->toContain('public int $imported = 0;')
            ->toContain('$this->imported++')
            ->toContain('::createFromImport($row, true);');
    }
});
