<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\FleetOps\Exports\IssueExport;
use Illuminate\Support\Str;
use Fleetbase\FleetOps\Models\Issue;
use Maatwebsite\Excel\Facades\Excel;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;

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
     * @param ExportRequest $request
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $selections   = $request->input('selections', []);
        info("format:::::",[$selections]);
        $fileName = trim(Str::slug('issue-' . date('Y-m-d-H:i')) . '.' . $format);
        return Excel::download(new IssueExport($selections), $fileName);
    }
}