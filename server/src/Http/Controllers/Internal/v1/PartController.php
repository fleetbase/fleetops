<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\PartExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\PartImport;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class PartController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'part';

    /**
     * Export parts to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format     = $request->input('format', 'xlsx');
        $selections = $request->array('selections');
        $fileName   = trim(Str::slug('parts-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new PartExport($selections), $fileName);
    }

    /**
     * Process import files (excel, csv) into Part records.
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
                $import = new PartImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to process.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }
}
