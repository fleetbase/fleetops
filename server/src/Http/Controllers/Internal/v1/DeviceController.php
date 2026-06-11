<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $device  = $this->findDevice($id);
        $vehicle = $this->findVehicle($request->input('vehicle') ?? $request->input('attachable_uuid'));

        $device->attachTo($vehicle);
        $device->load(['telematic', 'warranty', 'attachable']);

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
        $device = $this->findDevice($id);
        $device->detach();
        $device->load(['telematic', 'warranty', 'attachable']);

        return response()->json([
            'status' => 'ok',
            'device' => $device,
        ]);
    }

    protected function findDevice(string $id): Device
    {
        return Device::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function findVehicle(?string $id): Vehicle
    {
        return Vehicle::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }
}
