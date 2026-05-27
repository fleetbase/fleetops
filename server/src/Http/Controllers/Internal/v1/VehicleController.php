<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\VehicleImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class VehicleController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'vehicle';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Vehicle $vehicle, array $input = [])
    {
        if ($this->hasDriverInput($request)) {
            $this->syncDriverAssignment($vehicle, $this->driverIdentifierFromRequest($request));
        }

        $customFieldValues = $request->array('vehicle.custom_field_values');
        if ($customFieldValues) {
            $vehicle->syncCustomFieldValues($customFieldValues);
        }
    }

    protected function hasDriverInput(Request $request): bool
    {
        $payload = $request->all();

        return Arr::has($payload, 'vehicle.driver_uuid')
            || Arr::has($payload, 'vehicle.driver.uuid')
            || Arr::has($payload, 'vehicle.driver.id')
            || Arr::has($payload, 'driver_uuid')
            || Arr::has($payload, 'driver');
    }

    protected function driverIdentifierFromRequest(Request $request): ?string
    {
        $payload = $request->all();
        $driver  = data_get($payload, 'vehicle.driver') ?? data_get($payload, 'driver');

        return data_get($payload, 'vehicle.driver_uuid')
            ?? data_get($payload, 'vehicle.driver.uuid')
            ?? data_get($payload, 'vehicle.driver.id')
            ?? (is_string($driver) ? $driver : null)
            ?? data_get($payload, 'driver_uuid')
            ?? data_get($payload, 'driver.uuid')
            ?? data_get($payload, 'driver.id');
    }

    protected function syncDriverAssignment(Vehicle $vehicle, ?string $identifier): void
    {
        if (empty($identifier)) {
            $vehicle->unassignDriver();

            return;
        }

        $driver = Driver::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(function ($query) use ($identifier) {
                $query->where('uuid', $identifier)->orWhere('public_id', $identifier);
            })
            ->when(session('company'), fn ($query, $company) => $query->where('company_uuid', $company))
            ->first();

        if ($driver) {
            $vehicle->assignDriver($driver);
            $vehicle->setRelation('driver', $driver);
        }
    }

    /**
     * Get all status options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function statuses()
    {
        $statuses = DB::table('vehicles')
            ->select('status')
            ->where('company_uuid', session('company'))
            ->distinct()
            ->get()
            ->pluck('status')
            ->filter()
            ->values();

        return response()->json($statuses);
    }

    /**
     * Get all avatar options for an vehicle.
     *
     * @return \Illuminate\Http\Response
     */
    public function avatars()
    {
        $options = Vehicle::getAvatarOptions(function ($query) {
            $query->where('company_uuid', session('company'));
        });

        return response()->json($options);
    }

    /**
     * Export the vehicles to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('vehicles-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new VehicleExport($selections), $fileName);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = new VehicleImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }
}
