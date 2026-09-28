<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\ServiceAreaExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
     *
     * Reads the custom field values from the payload the base controller already extracted
     * rather than from the raw request: Ember Data sends the resource under a camelCase root
     * (`serviceArea`), so `$request->array('service_area.custom_field_values')` was always empty and no
     * custom field value was ever persisted for this resource.
     */
    public function afterSave(Request $request, ServiceArea $serviceArea, array $input = [])
    {
        $customFieldValues = Arr::get($input, 'custom_field_values');
        if (is_array($customFieldValues) && $customFieldValues) {
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

        return static::downloadExport(new ServiceAreaExport($selections), $fileName);
    }

    protected static function downloadExport(ServiceAreaExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }
}
