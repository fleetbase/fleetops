<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'device';

    /**
     * Query callback when querying record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onQueryRecord($query, $request): void
    {
        $query->with(['telematic', 'warranty', 'attachable']);

        if ($request->filled('attachment_state')) {
            match ($request->input('attachment_state')) {
                'attached'   => $query->whereNotNull('attachable_uuid'),
                'unattached' => $query->whereNull('attachable_uuid'),
                default      => null,
            };
        }

        if ($request->filled('vehicle')) {
            $query->where('attachable_uuid', $request->input('vehicle'));
        }

        if ($request->filled('device_id')) {
            $query->where('device_id', 'like', '%' . $request->input('device_id') . '%');
        }
    }

    /**
     * Query callback when finding record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onFindRecord($query, $request): void
    {
        $query->with(['telematic', 'warranty', 'attachable']);
    }

    /**
     * Attach a device to a supported FleetOps resource.
     */
    public function attach(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'vehicle' => 'required_without:attachable_uuid|nullable|string',
        ]);

        $vehicleId = $request->input('vehicle') ?? $request->input('attachable_uuid');
        $device    = $this->resolveDevice($id);
        $vehicle   = $this->resolveVehicle($vehicleId);

        if (!$device) {
            $this->logDeviceAttachmentLookupFailure('attach', 'device', $id, $vehicleId);

            return response()->error('Device not found or not available for this organization.', 404);
        }

        if (!$vehicle) {
            $this->logDeviceAttachmentLookupFailure('attach', 'vehicle', $id, $vehicleId);

            return response()->error('Vehicle not found or not available for this organization.', 404);
        }

        try {
            $device->attachTo($vehicle);
            $device->load(['telematic', 'warranty', 'attachable']);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('attach', $device, $vehicle, $e);

            return response()->error('Unable to attach device to vehicle. Please try again or contact support.', 500);
        }

        return response()->json([
            'status' => 'ok',
            'device' => $device,
        ]);
    }

    /**
     * Detach a device from its current FleetOps resource.
     */
    public function detach(string $id): JsonResponse
    {
        $device = $this->resolveDevice($id);

        if (!$device) {
            $this->logDeviceAttachmentLookupFailure('detach', 'device', $id, null);

            return response()->error('Device not found or not available for this organization.', 404);
        }

        try {
            $device->detach();
            $device->load(['telematic', 'warranty', 'attachable']);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('detach', $device, null, $e);

            return response()->error('Unable to detach device from vehicle. Please try again or contact support.', 500);
        }

        return response()->json([
            'status' => 'ok',
            'device' => $device,
        ]);
    }

    protected function resolveDevice(string $id): ?Device
    {
        return Device::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->first();
    }

    protected function resolveVehicle(?string $id): ?Vehicle
    {
        if (!$id) {
            return null;
        }

        return Vehicle::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->first();
    }

    protected function logDeviceAttachmentLookupFailure(string $action, string $missingResource, string $deviceId, ?string $vehicleId): void
    {
        Log::warning('Device attachment lookup failed', [
            'action'           => $action,
            'missing_resource' => $missingResource,
            'device_id'        => $deviceId,
            'vehicle_id'       => $vehicleId,
            'company_uuid'     => session('company'),
            'request_id'       => request()->headers->get('X-Request-ID') ?? request()->headers->get('X-Correlation-ID'),
        ]);
    }

    protected function logDeviceAttachmentFailure(string $action, Device $device, ?Vehicle $vehicle, \Throwable $exception): void
    {
        Log::error('Device attachment failed', [
            'action'          => $action,
            'device_uuid'     => $device->uuid,
            'device_id'       => $device->public_id,
            'vehicle_uuid'    => $vehicle?->uuid,
            'vehicle_id'      => $vehicle?->public_id,
            'company_uuid'    => session('company'),
            'request_id'      => request()->headers->get('X-Request-ID') ?? request()->headers->get('X-Correlation-ID'),
            'exception_class' => get_class($exception),
            'exception'       => $exception->getMessage(),
        ]);
    }
}
