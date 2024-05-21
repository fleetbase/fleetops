<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\IssueExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\FleetOps\Imports\IssueImport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\Models\File;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class IssueController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'issue';

    /**
     * Export the issue to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('issue-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new IssueExport($selections), $fileName);
    }

      /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new IssueImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        // prepare imports and fix phone
        $imports = $imports->map(
            function ($row) {
         
               // handle created at
               if (isset($row['created at'])) {
                $row['created_at'] = $row['created at'];
                 unset($row['created at']);
                }

               if (isset($row['assignee'])) {
                   $row['assignee'] = $row['assignee'];
                    unset($row['assignee']);
                   }   

               if (isset($row['driver'])) {
                   $row['driver_name'] = $row['driver'];
                   }
                
                // Handle internal id
                if (isset($row['internal id'])) {
                    $row['internal_id'] = $row['internal id'];
                    unset($row['internal id']);
                }

                // set default values
                $row['status'] = 'pending';
                

                return $row;
            })->values()->toArray();

        // dd($imports);
        Issue::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
