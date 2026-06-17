<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionLink;
use Fleetbase\FleetOps\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspectionFormController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'inspection-form';

    public function publish(string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        $form->publish();

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection form published.',
            'data' => $form->fresh(),
        ]);
    }

    public function archive(string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        $form->archive();

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection form archived.',
            'data' => $form->fresh(),
        ]);
    }

    public function generateLink(Request $request, string $id): JsonResponse
    {
        $form = $this->resolveForm($id)
            ->firstOrFail();

        if (!$form->is_published) {
            return response()->json([
                'error' => 'Inspection form must be published before generating a public link.',
            ], 422);
        }

        $validated = $request->validate([
            'driver'     => 'nullable|string',
            'vehicle'    => 'nullable|string',
            'expires_at' => 'nullable|date',
            'single_use' => 'nullable|boolean',
        ]);

        $driver = $this->resolveDriver(data_get($validated, 'driver'));
        $vehicle = $this->resolveVehicle(data_get($validated, 'vehicle'));
        $token = InspectionLink::generateToken();

        $link = InspectionLink::create([
            'company_uuid'         => $form->company_uuid,
            'inspection_form_uuid' => $form->uuid,
            'driver_uuid'          => $driver?->uuid,
            'vehicle_uuid'         => $vehicle?->uuid,
            'created_by_uuid'      => session('user'),
            'token_hash'           => InspectionLink::hashToken($token),
            'status'               => 'active',
            'single_use'           => data_get($validated, 'single_use', true),
            'expires_at'           => data_get($validated, 'expires_at'),
        ]);

        $path = '/inspection?id=' . urlencode($form->public_id ?? $form->uuid) . '&token=' . urlencode($token);

        return response()->json([
            'status' => 'ok',
            'message' => 'Inspection link generated.',
            'link' => [
                'id'         => $link->public_id,
                'path'       => $path,
                'token'      => $token,
                'expires_at' => $link->expires_at,
                'driver'     => $driver ? ['id' => $driver->public_id, 'name' => $driver->name] : null,
                'vehicle'    => $vehicle ? ['id' => $vehicle->public_id, 'name' => $vehicle->display_name ?? $vehicle->name] : null,
            ],
        ]);
    }

    protected function resolveDriver(?string $id): ?Driver
    {
        if (!$id) {
            return null;
        }

        return Driver::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();
    }

    protected function resolveForm(string $id)
    {
        return InspectionForm::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            });
    }

    protected function resolveVehicle(?string $id): ?Vehicle
    {
        if (!$id) {
            return null;
        }

        return Vehicle::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('uuid', $id)->orWhere('public_id', $id);
            })
            ->firstOrFail();
    }
}
