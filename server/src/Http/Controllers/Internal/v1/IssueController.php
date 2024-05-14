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

    protected array $selections = [];

    public function __construct(array $selections = [])
    {
        $this->selections = $selections;
    }

    public function collection()
    {
        if ($this->selections) {
            return Issue::where('company_uuid', session('company'))->whereIn('uuid', $this->selections)->get();
        }

        return Issue::where('company_uuid', session('company'))->get();
    }

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
        $fileName = trim(Str::slug('issue-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new IssueExport($selections), $fileName);
    }

}

