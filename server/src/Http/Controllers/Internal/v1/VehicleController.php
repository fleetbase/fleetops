<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\VehicleExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\Index\Order as IndexOrderResource;
use Fleetbase\FleetOps\Imports\VehicleImport;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function assignDriver(Request $request, string $id): JsonResponse
    {
        $request->validate(['driver' => 'required|string']);

        $vehicle = $this->findVehicle($id);
        $driver  = $this->findDriver($request->input('driver'));

        $vehicle->assignDriver($driver);
        $vehicle->load(['driver', 'devices']);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Driver assigned to vehicle.',
            'vehicle' => $vehicle,
        ]);
    }

    public function unassignDriver(string $id): JsonResponse
    {
        $vehicle = $this->findVehicle($id);
        $vehicle->unassignDriver();
        $vehicle->load(['driver', 'devices']);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Driver unassigned from vehicle.',
            'vehicle' => $vehicle,
        ]);
    }

    public function assignedOrders(string $id): JsonResponse
    {
        $vehicle = $this->findVehicle($id);
        $orders  = Order::where('vehicle_assigned_uuid', $vehicle->uuid)
            ->where('company_uuid', session('company'))
            ->with(['payload', 'trackingNumber', 'orderConfig', 'driverAssigned', 'vehicleAssigned'])
            ->orderByRaw('uuid = ? desc', [$this->activeOrderUuid($vehicle)])
            ->latest()
            ->get();

        return response()->json([
            'status'  => 'ok',
            'vehicle' => $vehicle->fresh(['driver', 'devices']),
            'orders'  => IndexOrderResource::collection($orders)->resolve(),
            'current' => $this->activeOrderUuid($vehicle),
            'count'   => $orders->count(),
        ]);
    }

    public function unassignOrders(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'orders'   => 'required|array|min:1',
            'orders.*' => 'required|string',
        ]);

        $vehicle = $this->findVehicle($id);
        $ids     = collect($request->input('orders'))->filter()->unique()->values();
        $orders  = Order::where('vehicle_assigned_uuid', $vehicle->uuid)
            ->where('company_uuid', session('company'))
            ->where(function ($query) use ($ids) {
                $query->whereIn('uuid', $ids)->orWhereIn('public_id', $ids);
            })
            ->get();

        if ($orders->isEmpty()) {
            return response()->error('No assigned orders were selected for this vehicle.');
        }

        DB::transaction(function () use ($orders): void {
            Order::whereIn('uuid', $orders->pluck('uuid'))->update([
                'vehicle_assigned_uuid' => null,
                'updated_at'            => now(),
            ]);
        });

        return response()->json([
            'status'  => 'ok',
            'message' => 'Vehicle unassigned from selected orders.',
            'vehicle' => $vehicle->fresh(['driver', 'devices']),
            'orders'  => IndexOrderResource::collection($orders->fresh(['driverAssigned', 'vehicleAssigned']))->resolve(),
            'count'   => $orders->count(),
        ]);
    }

    public function attachDevice(Request $request, string $id): JsonResponse
    {
        $request->validate(['device' => 'required|string']);

        $deviceId = $request->input('device');
        $vehicle  = $this->resolveVehicle($id);
        $device   = $this->resolveDevice($deviceId);

        if (!$vehicle) {
            $this->logDeviceAttachmentLookupFailure('attach-device', 'vehicle', $id, $deviceId);

            return response()->error('Vehicle not found or not available for this organization.', 404);
        }

        if (!$device) {
            $this->logDeviceAttachmentLookupFailure('attach-device', 'device', $id, $deviceId);

            return response()->error('Device not found or not available for this organization.', 404);
        }

        try {
            $device->attachTo($vehicle);
            $device->load(['telematic', 'warranty', 'attachable']);
            $vehicle->load(['driver', 'devices']);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('attach-device', $vehicle, $device, $e);

            return response()->error('Unable to attach device to vehicle. Please try again or contact support.', 500);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Device attached to vehicle.',
            'device'  => $device,
            'vehicle' => $vehicle,
        ]);
    }

    public function detachDevice(Request $request, string $id): JsonResponse
    {
        $request->validate(['device' => 'required|string']);

        $deviceId = $request->input('device');
        $vehicle  = $this->resolveVehicle($id);
        $device   = $this->resolveDevice($deviceId);

        if (!$vehicle) {
            $this->logDeviceAttachmentLookupFailure('detach-device', 'vehicle', $id, $deviceId);

            return response()->error('Vehicle not found or not available for this organization.', 404);
        }

        if (!$device) {
            $this->logDeviceAttachmentLookupFailure('detach-device', 'device', $id, $deviceId);

            return response()->error('Device not found or not available for this organization.', 404);
        }

        if ($device->attachable_uuid !== $vehicle->uuid) {
            return response()->error('This device is not attached to the selected vehicle.');
        }

        try {
            $device->detach();
            $device->load(['telematic', 'warranty', 'attachable']);
            $vehicle->load(['driver', 'devices']);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('detach-device', $vehicle, $device, $e);

            return response()->error('Unable to detach device from vehicle. Please try again or contact support.', 500);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => 'Device detached from vehicle.',
            'device'  => $device,
            'vehicle' => $vehicle,
        ]);
    }

    protected function findVehicle(string $id): Vehicle
    {
        return Vehicle::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function resolveVehicle(string $id): ?Vehicle
    {
        return Vehicle::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->first();
    }

    protected function findDriver(string $id): Driver
    {
        return Driver::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function resolveDevice(string $id): ?Device
    {
        return Device::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->first();
    }

    protected function logDeviceAttachmentLookupFailure(string $action, string $missingResource, string $vehicleId, ?string $deviceId): void
    {
        Log::warning('Vehicle device attachment lookup failed', [
            'action'           => $action,
            'missing_resource' => $missingResource,
            'vehicle_id'       => $vehicleId,
            'device_id'        => $deviceId,
            'company_uuid'     => session('company'),
            'request_id'       => request()->headers->get('X-Request-ID') ?? request()->headers->get('X-Correlation-ID'),
        ]);
    }

    protected function logDeviceAttachmentFailure(string $action, Vehicle $vehicle, Device $device, \Throwable $exception): void
    {
        Log::error('Vehicle device attachment failed', [
            'action'          => $action,
            'vehicle_uuid'    => $vehicle->uuid,
            'vehicle_id'      => $vehicle->public_id,
            'device_uuid'     => $device->uuid,
            'device_id'       => $device->public_id,
            'company_uuid'    => session('company'),
            'request_id'      => request()->headers->get('X-Request-ID') ?? request()->headers->get('X-Correlation-ID'),
            'exception_class' => get_class($exception),
            'exception'       => $exception->getMessage(),
        ]);
    }

    protected function activeOrderUuid(Vehicle $vehicle): ?string
    {
        $vehicle->loadMissing('driver.currentOrder');

        return data_get($vehicle, 'driver.currentOrder.uuid') ?? data_get($vehicle->lastKnownPosition(), 'order_uuid');
    }

    protected function findDevice(string $id): Device
    {
        return Device::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
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
