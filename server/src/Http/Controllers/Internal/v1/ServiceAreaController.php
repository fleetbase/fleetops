<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ServiceAreaExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ServiceAreaController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'service_area';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, ServiceArea $serviceArea)
    {
        $customFieldValues = $request->array('service_area.custom_field_values');
        if ($customFieldValues) {
            $serviceArea->syncCustomFieldValues($customFieldValues);
        }
    }

    /**
     * Export the fleets to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('service-areas-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new ServiceAreaExport($selections), $fileName);
    }
}
