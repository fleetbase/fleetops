<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\EquipmentExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\EquipmentImport;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class EquipmentController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'equipment';

    /**
     * Export equipment to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format     = $request->input('format', 'xlsx');
        $selections = $request->array('selections');
        $fileName   = trim(Str::slug('equipment-' . date('Y-m-d-H:i')) . '.' . $format);

        return $this->downloadExport(new EquipmentExport($selections), $fileName);
    }

    /**
     * Process import files (excel, csv) into Equipment records.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk          = $request->input('disk', config('filesystems.default'));
        $files         = $request->resolveFilesFromIds();
        $importedCount = 0;

        foreach ($files as $file) {
            try {
                $import = $this->createImport();
                $this->importFile($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to process.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    protected function downloadExport(EquipmentExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }

    protected function createImport(): EquipmentImport
    {
        return new EquipmentImport();
    }

    protected function importFile(EquipmentImport $import, string $path, string $disk): void
    {
        Excel::import($import, $path, $disk);
    }
}
