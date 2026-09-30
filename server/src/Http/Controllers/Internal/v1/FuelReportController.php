<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\FuelReportExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\FuelReportImport;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FuelReportController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'fuel_report';

    /**
     * Handle post save transactions.
     *
     * Reads the custom field values from the payload the base controller already extracted
     * rather than from the raw request: Ember Data sends the resource under a camelCase root
     * (`fuelReport`), so `$request->array('fuel_report.custom_field_values')` was always empty and no
     * custom field value was ever persisted for this resource.
     */
    public function afterSave(Request $request, FuelReport $fuelReport, array $input = [])
    {
        $customFieldValues = Arr::get($input, 'custom_field_values');
        if (is_array($customFieldValues) && $customFieldValues) {
            $fuelReport->syncCustomFieldValues($customFieldValues);
        }
    }

    /**
     * Export the fleets to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('fuel_report-' . date('Y-m-d-H:i')) . '.' . $format);

        return $this->downloadExport(new FuelReportExport($selections), $fileName);
    }

    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = $this->createImport();
                $this->importFile($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }

    protected function downloadExport(FuelReportExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }

    protected function createImport(): FuelReportImport
    {
        return new FuelReportImport();
    }

    protected function importFile(FuelReportImport $import, string $path, string $disk): void
    {
        Excel::import($import, $path, $disk);
    }
}
