<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\FleetExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\FleetActionRequest;
use Fleetbase\FleetOps\Imports\FleetImport;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\LiveCacheService;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FleetController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'fleet';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Fleet $fleet)
    {
        $customFieldValues = $request->array('fleet.custom_field_values');
        if ($customFieldValues) {
            $fleet->syncCustomFieldValues($customFieldValues);
        }
    }

    /**
     * Query callback when querying record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onQueryRecord($query, $request): void
    {
        if ($request->has('excludeDriverJobs')) {
            $excludeJobs = $request->array('excludeDriverJobs');
            $query->with('drivers', function ($query) use ($excludeJobs) {
                $query->with('jobs', function ($query) use ($excludeJobs) {
                    if (is_array($excludeJobs)) {
                        $isUuids = collect($excludeJobs)->every(function ($id) {
                            return Str::isUuid($id);
                        });

                        if ($isUuids) {
                            $query->whereNotIn('uuid', $excludeJobs);
                        } else {
                            $query->whereNotIn('public_id', $excludeJobs);
                        }
                    }

                    $query->whereHas(
                        'payload',
                        function ($q) {
                            $q->where(
                                function ($q) {
                                    $q->whereHas('waypoints');
                                    $q->orWhereHas('pickup');
                                    $q->orWhereHas('dropoff');
                                }
                            );
                            $q->with(['entities', 'waypoints', 'dropoff', 'pickup', 'return']);
                        }
                    );
                    $query->whereHas('trackingNumber');
                    $query->whereHas('trackingStatuses');
                    $query->with(
                        [
                            'payload',
                            'trackingNumber',
                            'trackingStatuses',
                        ]
                    );
                });
            });
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
        $fileName     = trim(Str::slug('fleets-' . date('Y-m-d-H:i')) . '.' . $format);

        return static::downloadExport(new FleetExport($selections), $fileName);
    }

    /**
     * Removes a driver from a fleet.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function removeDriver(FleetActionRequest $request)
    {
        $fleet  = static::findFleetByUuid($request->input('fleet'));
        $driver = static::findDriverByUuid($request->input('driver'));

        // check if driver is already in this fleet
        $deleted = static::deleteFleetDriverAssignment($fleet->uuid, $driver->uuid);

        static::invalidateOperationsMonitor();

        return static::jsonResponse(static::removedAssignmentPayload($deleted));
    }

    /**
     * Adds a driver to a fleet.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function assignDriver(FleetActionRequest $request)
    {
        $fleet  = static::findFleetByUuid($request->input('fleet'));
        $driver = static::findDriverByUuid($request->input('driver'));
        $added  = false;

        // check if driver is already in this fleet
        $exists = static::fleetDriverAssignmentExists($fleet->uuid, $driver->uuid);

        if (!$exists) {
            $added = static::createFleetDriverAssignment($fleet->uuid, $driver->uuid);
        }

        static::invalidateOperationsMonitor();

        return static::jsonResponse(static::assignmentPayload($exists, $added));
    }

    /**
     * Removes a vehicle from a fleet.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function removeVehicle(FleetActionRequest $request)
    {
        $fleet   = static::findFleetByUuid($request->input('fleet'));
        $vehicle = static::findVehicleByUuid($request->input('vehicle'));

        // check if vehicle is already in this fleet
        $deleted = static::deleteFleetVehicleAssignment($fleet->uuid, $vehicle->uuid);

        static::invalidateOperationsMonitor();

        return static::jsonResponse(static::removedAssignmentPayload($deleted));
    }

    /**
     * Adds a vehicle to a fleet.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function assignVehicle(FleetActionRequest $request)
    {
        $fleet   = static::findFleetByUuid($request->input('fleet'));
        $vehicle = static::findVehicleByUuid($request->input('vehicle'));
        $added   = false;

        // check if vehicle is already in this fleet
        $exists = static::fleetVehicleAssignmentExists($fleet->uuid, $vehicle->uuid);

        if (!$exists) {
            $added = static::createFleetVehicleAssignment($fleet->uuid, $vehicle->uuid);
        }

        static::invalidateOperationsMonitor();

        return static::jsonResponse(static::assignmentPayload($exists, $added));
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

        return static::jsonResponse(static::importCompletedPayload($importedCount));
    }

    protected static function downloadExport(FleetExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }

    protected static function findFleetByUuid(string $uuid): ?Fleet
    {
        return Fleet::where('uuid', $uuid)->first();
    }

    protected static function findDriverByUuid(string $uuid): ?Driver
    {
        return Driver::where('uuid', $uuid)->first();
    }

    protected static function findVehicleByUuid(string $uuid): ?Vehicle
    {
        return Vehicle::where('uuid', $uuid)->first();
    }

    protected static function fleetDriverAssignmentExists(string $fleetUuid, string $driverUuid): bool
    {
        return FleetDriver::where([
            'fleet_uuid'  => $fleetUuid,
            'driver_uuid' => $driverUuid,
        ])->exists();
    }

    protected static function createFleetDriverAssignment(string $fleetUuid, string $driverUuid): FleetDriver
    {
        return FleetDriver::create([
            'fleet_uuid'  => $fleetUuid,
            'driver_uuid' => $driverUuid,
        ]);
    }

    protected static function deleteFleetDriverAssignment(string $fleetUuid, string $driverUuid): mixed
    {
        return FleetDriver::where([
            'fleet_uuid'  => $fleetUuid,
            'driver_uuid' => $driverUuid,
        ])->delete();
    }

    protected static function fleetVehicleAssignmentExists(string $fleetUuid, string $vehicleUuid): bool
    {
        return FleetVehicle::where([
            'fleet_uuid'   => $fleetUuid,
            'vehicle_uuid' => $vehicleUuid,
        ])->exists();
    }

    protected static function createFleetVehicleAssignment(string $fleetUuid, string $vehicleUuid): FleetVehicle
    {
        return FleetVehicle::create([
            'fleet_uuid'   => $fleetUuid,
            'vehicle_uuid' => $vehicleUuid,
        ]);
    }

    protected static function deleteFleetVehicleAssignment(string $fleetUuid, string $vehicleUuid): mixed
    {
        return FleetVehicle::where([
            'fleet_uuid'   => $fleetUuid,
            'vehicle_uuid' => $vehicleUuid,
        ])->delete();
    }

    protected static function invalidateOperationsMonitor(): void
    {
        LiveCacheService::invalidate('operations-monitor');
    }

    protected function createImport(): FleetImport
    {
        return new FleetImport();
    }

    protected function importFile(FleetImport $import, string $path, string $disk): void
    {
        Excel::import($import, $path, $disk);
    }

    protected static function jsonResponse(array $payload)
    {
        return response()->json($payload);
    }

    protected static function assignmentPayload(bool $exists, mixed $added): array
    {
        return [
            'status' => 'ok',
            'exists' => $exists,
            'added'  => (bool) $added,
        ];
    }

    protected static function removedAssignmentPayload(mixed $deleted): array
    {
        return [
            'status'  => 'ok',
            'deleted' => $deleted,
        ];
    }

    protected static function importCompletedPayload(int $importedCount): array
    {
        return ['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount];
    }
}
