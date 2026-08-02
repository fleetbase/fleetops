<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateDeviceRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateDeviceRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Device as DeviceResource;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DeviceController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreateDeviceRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $device = $this->loadDeviceRelations($this->createDevice($input));

        return $this->deviceResource($device);
    }

    public function update(string $id, UpdateDeviceRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        try {
            $device = $this->resolveModel(Device::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Device resource not found.'], 404);
        }

        $this->updateDevice($device, $this->input($request));

        return $this->deviceResource($this->loadDeviceRelations($device->refresh()));
    }

    public function query(Request $request)
    {
        $this->rejectUuidIdentifiers($request);

        $results = $this->queryDevicesWithRequest($request, function (&$query) {
            $query->with(['telematic', 'warranty', 'attachable', 'photo'])->withCount('sensors');
        });

        return $this->deviceResourceCollection($results);
    }

    public function find(string $id)
    {
        try {
            $device = $this->loadDeviceRelations($this->resolveModel(Device::class, $id));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Device resource not found.'], 404);
        }

        return $this->deviceResource($device);
    }

    public function delete(string $id)
    {
        try {
            $device = $this->resolveModel(Device::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Device resource not found.'], 404);
        }

        $device->delete();

        return $this->deletedDeviceResource($device);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        $this->rejectUuidIdentifiers($request);

        $request->validate([
            'vehicle' => 'required_without:attachable|string|nullable',
        ]);

        $deviceId  = $id;
        $vehicleId = $request->input('vehicle') ?? $request->input('attachable');

        try {
            $device  = $this->resolveModel(Device::class, $deviceId);
            $vehicle = $this->resolveModel(Vehicle::class, $vehicleId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->logDeviceAttachmentLookupFailure('attach', $deviceId, $vehicleId);

            return response()->json(['error' => 'Device or vehicle resource not found.'], 404);
        }

        try {
            $device->attachTo($vehicle);
            $this->loadDeviceRelations($device);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('attach', $device, $vehicle, $e);

            return response()->json(['error' => 'Unable to attach device to vehicle.'], 500);
        }

        return response()->json([
            'status' => 'ok',
            'device' => $this->deviceResource($device),
        ]);
    }

    public function detach(string $id): JsonResponse
    {
        try {
            $device = $this->resolveModel(Device::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->logDeviceAttachmentLookupFailure('detach', $id, null);

            return response()->json(['error' => 'Device resource not found.'], 404);
        }

        try {
            $device->detach();
            $this->loadDeviceRelations($device);
        } catch (\Throwable $e) {
            $this->logDeviceAttachmentFailure('detach', $device, null, $e);

            return response()->json(['error' => 'Unable to detach device from vehicle.'], 500);
        }

        return response()->json([
            'status' => 'ok',
            'device' => $this->deviceResource($device),
        ]);
    }

    protected function createDevice(array $input): Device
    {
        return Device::create($input);
    }

    protected function updateDevice(Device $device, array $input): bool
    {
        return $device->update($input);
    }

    protected function queryDevicesWithRequest(Request $request, callable $callback): mixed
    {
        return Device::queryWithRequest($request, $callback);
    }

    protected function loadDeviceRelations(Device $device): Device
    {
        return $device->load(['telematic', 'warranty', 'attachable', 'photo'])->loadCount('sensors');
    }

    protected function deviceResource(Device $device): mixed
    {
        return new DeviceResource($device);
    }

    protected function deviceResourceCollection(mixed $results): mixed
    {
        return DeviceResource::collection($results);
    }

    protected function deletedDeviceResource(Device $device): mixed
    {
        return new DeletedResource($device);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'type',
            'device_id',
            'internal_id',
            'imei',
            'imsi',
            'firmware_version',
            'provider',
            'name',
            'model',
            'location',
            'manufacturer',
            'serial_number',
            'installation_date',
            'last_maintenance_date',
            'meta',
            'data',
            'options',
            'online',
            'status',
            'data_frequency',
            'notes',
            'last_online_at',
        ]);

        if ($request->filled(['latitude', 'longitude'])) {
            $input['last_position'] = new Point($request->input('latitude'), $request->input('longitude'));
        } elseif ($request->filled('last_position')) {
            $input['last_position'] = Utils::getPointFromCoordinates($request->input('last_position'));
        }

        $this->applyPublicIdRelation($input, 'telematic', 'telematic_uuid', Telematic::class, $request);
        $this->applyPublicIdRelation($input, 'warranty', 'warranty_uuid', Warranty::class, $request);
        $this->applyPublicIdRelation($input, 'photo', 'photo_uuid', File::class, $request);

        if ($request->exists('attachable')) {
            if (blank($request->input('attachable'))) {
                $input['attachable_type'] = null;
                $input['attachable_uuid'] = null;
            } else {
                [$type, $uuid]            = $this->resolveMorph($request->input('attachable_type'), $request->input('attachable'));
                $input['attachable_type'] = $type;
                $input['attachable_uuid'] = $uuid;
            }
        }

        return $input;
    }

    protected function logDeviceAttachmentLookupFailure(string $action, string $deviceId, ?string $vehicleId): void
    {
        Log::warning('Public API device attachment lookup failed', [
            'action'       => $action,
            'device_id'    => $deviceId,
            'vehicle_id'   => $vehicleId,
            'company_uuid' => session('company'),
            'request_id'   => request()->headers->get('X-Request-ID') ?? request()->headers->get('X-Correlation-ID'),
        ]);
    }

    protected function logDeviceAttachmentFailure(string $action, Device $device, ?Vehicle $vehicle, \Throwable $exception): void
    {
        Log::error('Public API device attachment failed', [
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
