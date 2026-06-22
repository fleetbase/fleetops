<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateEquipmentRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateEquipmentRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Equipment as EquipmentResource;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreateEquipmentRequest $request)
    {
        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $equipment = Equipment::create($input)->load(['warranty', 'photo', 'equipable']);

        return new EquipmentResource($equipment);
    }

    public function update(string $id, UpdateEquipmentRequest $request)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Equipment resource not found.'], 404);
        }

        $equipment->update($this->input($request));

        return new EquipmentResource($equipment->refresh()->load(['warranty', 'photo', 'equipable']));
    }

    public function query(Request $request)
    {
        $results = Equipment::queryWithRequest($request, function (&$query) {
            $query->with(['warranty', 'photo', 'equipable']);
        });

        return EquipmentResource::collection($results);
    }

    public function find(string $id)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id)->load(['warranty', 'photo', 'equipable']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Equipment resource not found.'], 404);
        }

        return new EquipmentResource($equipment);
    }

    public function delete(string $id)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(['error' => 'Equipment resource not found.'], 404);
        }

        $equipment->delete();

        return new DeletedResource($equipment);
    }

    protected function input(Request $request): array
    {
        $input = $request->only([
            'name',
            'code',
            'type',
            'status',
            'serial_number',
            'manufacturer',
            'model',
            'purchased_at',
            'purchase_price',
            'currency',
            'meta',
        ]);

        $this->applyPublicIdRelation($input, 'warranty', 'warranty_uuid', Warranty::class, $request);
        $this->applyPublicIdRelation($input, 'photo', 'photo_uuid', File::class, $request);

        if ($request->exists('equipable')) {
            if (blank($request->input('equipable'))) {
                $input['equipable_type'] = null;
                $input['equipable_uuid'] = null;
            } else {
                [$type, $uuid] = $this->resolveMorph($request->input('equipable_type'), $request->input('equipable'));
                $input['equipable_type'] = $type;
                $input['equipable_uuid'] = $uuid;
            }
        }

        return $input;
    }
}
