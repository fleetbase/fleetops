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
use Illuminate\Support\Facades\DB;

class EquipmentController extends Controller
{
    use ResolvesFleetOpsApiResources;

    public function create(CreateEquipmentRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        $equipment = $this->createEquipment($input)->load(['warranty', 'photo', 'equipable']);

        return $this->equipmentResource($equipment);
    }

    public function update(string $id, UpdateEquipmentRequest $request)
    {
        $this->rejectUuidIdentifiers($request);

        try {
            $equipment = $this->resolveModel(Equipment::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Equipment resource not found.'], 404);
        }

        $equipment->update($this->input($request));

        return $this->equipmentResource($equipment->refresh()->load(['warranty', 'photo', 'equipable']));
    }

    public function query(Request $request)
    {
        $this->rejectUuidIdentifiers($request);

        $results = $this->queryEquipment($request, function (&$query) {
            $query->with(['warranty', 'photo', 'equipable']);
        });

        return $this->equipmentResourceCollection($results);
    }

    public function find(string $id)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id)->load(['warranty', 'photo', 'equipable']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Equipment resource not found.'], 404);
        }

        return $this->equipmentResource($equipment);
    }

    public function delete(string $id)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Equipment resource not found.'], 404);
        }

        $equipment->delete();

        return $this->deletedEquipmentResource($equipment);
    }

    public function attach(Request $request, string $id)
    {
        $this->rejectUuidIdentifiers($request);
        $request->validate(['attachable_type' => ['required', 'in:fleet-ops:vehicle,fleet-ops:trailer,vehicle,trailer'], 'attachable' => ['required', 'string']]);
        try {
            $equipment     = $this->resolveModel(Equipment::class, $id);
            [$type, $uuid] = $this->resolveMorph($request->attachable_type, $request->attachable);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Equipment or attachable resource not found.'], 404);
        }
        $equipment = DB::transaction(function () use ($equipment, $type, $uuid) {
            $locked = Equipment::where('company_uuid', session('company'))->where('uuid', $equipment->uuid)->lockForUpdate()->firstOrFail();
            if ($locked->equipable_type === $type && $locked->equipable_uuid === $uuid) {
                return $locked;
            }
            $locked->update(['equipable_type' => $type, 'equipable_uuid' => $uuid]);
            activity('equipment_attached')->performedOn($locked)->withProperties(['attachable_type' => $type, 'attachable_uuid' => $uuid])->log('Equipment attached');

            return $locked;
        });

        return $this->equipmentResource($equipment->refresh()->load(['warranty', 'photo', 'equipable']));
    }

    public function detach(string $id)
    {
        try {
            $equipment = $this->resolveModel(Equipment::class, $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['error' => 'Equipment resource not found.'], 404);
        }
        $equipment = DB::transaction(function () use ($equipment) {
            $locked = Equipment::where('company_uuid', session('company'))->where('uuid', $equipment->uuid)->lockForUpdate()->firstOrFail();
            if (!$locked->equipable_uuid) {
                return $locked;
            }
            $previous = ['attachable_type' => $locked->equipable_type, 'attachable_uuid' => $locked->equipable_uuid];
            $locked->update(['equipable_type' => null, 'equipable_uuid' => null]);
            activity('equipment_detached')->performedOn($locked)->withProperties($previous)->log('Equipment detached');

            return $locked;
        });

        return $this->equipmentResource($equipment->refresh()->load(['warranty', 'photo', 'equipable']));
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
                [$type, $uuid]           = $this->resolveMorph($request->input('equipable_type'), $request->input('equipable'));
                $input['equipable_type'] = $type;
                $input['equipable_uuid'] = $uuid;
            }
        }

        return $input;
    }

    protected function createEquipment(array $input): Equipment
    {
        return Equipment::create($input);
    }

    protected function queryEquipment(Request $request, callable $callback)
    {
        return Equipment::queryWithRequest($request, $callback);
    }

    protected function equipmentResource(Equipment $equipment)
    {
        return new EquipmentResource($equipment);
    }

    protected function equipmentResourceCollection($results)
    {
        return EquipmentResource::collection($results);
    }

    protected function deletedEquipmentResource(Equipment $equipment)
    {
        return new DeletedResource($equipment);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
