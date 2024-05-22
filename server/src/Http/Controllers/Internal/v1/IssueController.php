<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\IssueExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\IssueImport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
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
                // Handle assignee
                if (isset($row['assignee'])) {
                    $assigneeUser = User::where('name', 'like', '%' . $row['assignee'] . '%')->where('company_uuid', session('user'))->first();
                    if ($assigneeUser) {
                        $row['assigned_to_uuid'] = $assigneeUser->uuid;
                    }
                    unset($row['assignee']);
                }

                // Handle driver
                if (isset($row['driver'])) {
                    $driverUser = User::where('name', 'like', '%' . $row['driver'] . '%')->where('company_uuid', session('user'))->first();
                    if ($driverUser) {
                        $row['driver_uuid'] = $driverUser->uuid;
                    }
                    unset($row['driver']);
                }

                // Handle reporter
                if (isset($row['reporter'])) {
                    $reporterUser = User::where('name', 'like', '%' . $row['reporter'] . '%')->where('company_uuid', session('user'))->first();
                    if ($reporterUser) {
                        $row['reported_by_uuid'] = $reporterUser->uuid;
                    }
                    unset($row['reporter']);
                }

                // Handle vehicle
                if (isset($row['vehicle'])) {
                    $vehicle = Vehicle::search($row['vehicle'])->where('company_uuid', session('user'))->first();
                    if ($vehicle) {
                        $row['vehicle_uuid'] = $vehicle->uuid;
                    }
                    unset($row['vehicle']);
                }

                // set default point for location columns if not set
                if (!isset($row['location'])) {
                    $row['location'] = Utils::parsePointToWkt(new Point(0, 0));
                }

                // set default values
                $row['status']       = 'pending';
                $row['company_uuid'] = session('company');

                return $row;
            })->values()->toArray();

        // dd($imports);
        Issue::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
