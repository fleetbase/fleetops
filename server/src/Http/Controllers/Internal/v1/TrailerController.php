<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\TrailerExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Resources\v1\AssetConnection as AssetConnectionResource;
use Fleetbase\FleetOps\Http\Resources\v1\Trailer as TrailerResource;
use Fleetbase\FleetOps\Imports\TrailerImport;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Support\Resolve;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TrailerController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'trailer';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Trailer $trailer): void
    {
        $values = $request->array('trailer.custom_field_values');

        if ($values) {
            $trailer->syncCustomFieldValues($values);
        }
    }

    /**
     * Deleting a trailer that is still coupled to a vehicle would orphan the active
     * towing connection, so it must be detached first.
     */
    public function deleteRecord($id, Request $request)
    {
        $trailer = $this->resolveTrailer($id);

        if ($trailer && $this->activeConnectionQuery($trailer)->exists()) {
            return response()->error('Detach the trailer from its vehicle before deleting it.', 409);
        }

        return $this->deleteTrailerRecord($id, $request);
    }

    // @codeCoverageIgnoreStart
    // Thin seam over the framework delete flow; the guard above is what this controller owns.
    protected function deleteTrailerRecord($id, Request $request)
    {
        return parent::deleteRecord($id, $request);
    }

    // @codeCoverageIgnoreEnd

    /**
     * Attach the trailer to a vehicle. Re-attaching to the same vehicle is idempotent.
     */
    public function attach(Request $request, string $id)
    {
        $request->validate(['vehicle' => ['required', 'string'], 'position' => ['nullable', 'integer', 'min:1']]);

        $trailer = $this->resolveTrailer($id);
        $vehicle = $this->resolveVehicle($request->input('vehicle'));

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        if (!$vehicle) {
            return response()->error('Vehicle not found or not available for this organization.', 404);
        }

        $result = DB::transaction(function () use ($trailer, $vehicle, $request) {
            $active = $this->activeConnectionQuery($trailer)->lockForUpdate()->first();

            if ($active) {
                if ($active->connector_uuid !== $vehicle->uuid) {
                    return 'Trailer is already attached to another vehicle. Detach it before moving.';
                }

                return $active;
            }

            $position    = $request->integer('position', 1);
            $positionKey = $vehicle->uuid . ':' . $position;

            if (AssetConnection::where('company_uuid', session('company'))->where('active_connector_position', $positionKey)->lockForUpdate()->exists()) {
                return 'Another trailer already occupies this towing position.';
            }

            return AssetConnection::create([
                'company_uuid'              => session('company'),
                'connector_type'            => Vehicle::class,
                'connector_uuid'            => $vehicle->uuid,
                'connected_type'            => Trailer::class,
                'connected_uuid'            => $trailer->uuid,
                'active_connected_uuid'     => $trailer->uuid,
                'active_connector_position' => $positionKey,
                'relationship_type'         => 'towing',
                'position'                  => $position,
                'connected_at'              => now(),
                'source'                    => 'manual',
                'created_by_uuid'           => session('user'),
                'updated_by_uuid'           => session('user'),
            ]);
        }, 3);

        if (is_string($result)) {
            return response()->error($result, 409);
        }

        return response()->json([
            'status'     => 'ok',
            'message'    => 'Trailer attached to vehicle.',
            'trailer'    => new TrailerResource($trailer->fresh(['currentConnection.vehicle'])),
            'connection' => new AssetConnectionResource($result->load(['vehicle', 'trailer'])),
        ]);
    }

    /**
     * End the active towing connection. Detaching an unattached trailer is a no-op.
     */
    public function detach(string $id)
    {
        $trailer = $this->resolveTrailer($id);

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        DB::transaction(fn () => $this->activeConnectionQuery($trailer)->lockForUpdate()->update([
            'active_connected_uuid'     => null,
            'active_connector_position' => null,
            'disconnected_at'           => now(),
            'updated_by_uuid'           => session('user'),
            'updated_at'                => now(),
        ]));

        return response()->json([
            'status'  => 'ok',
            'message' => 'Trailer detached from vehicle.',
            'trailer' => new TrailerResource($trailer->fresh(['currentConnection.vehicle'])),
        ]);
    }

    public function attachDevice(Request $request, string $id)
    {
        $request->validate(['device' => ['required', 'string']]);

        $trailer = $this->resolveTrailer($id);
        $device  = $this->resolveDevice($request->input('device'));

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        if (!$device) {
            return response()->error('Device not found or not available for this organization.', 404);
        }

        $device->attachTo($trailer);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Device attached to trailer.',
            'trailer' => new TrailerResource($trailer->fresh(['devices'])),
            'device'  => Resolve::httpResourceForModel($device->fresh(['attachable'])),
        ]);
    }

    public function detachDevice(Request $request, string $id)
    {
        $request->validate(['device' => ['required', 'string']]);

        $trailer = $this->resolveTrailer($id);
        $device  = $this->resolveDevice($request->input('device'));

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        if (!$device) {
            return response()->error('Device not found or not available for this organization.', 404);
        }

        if ($device->attachable_uuid !== $trailer->uuid || $device->attachable_type !== Trailer::class) {
            return response()->error('This device is not attached to the selected trailer.', 422);
        }

        $device->detach();

        return response()->json([
            'status'  => 'ok',
            'message' => 'Device detached from trailer.',
            'trailer' => new TrailerResource($trailer->fresh(['devices'])),
            'device'  => Resolve::httpResourceForModel($device->fresh(['attachable'])),
        ]);
    }

    public function attachEquipment(Request $request, string $id)
    {
        $request->validate(['equipment' => ['required', 'string']]);

        $trailer   = $this->resolveTrailer($id);
        $equipment = $this->resolveEquipment($request->input('equipment'));

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        if (!$equipment) {
            return response()->error('Equipment not found or not available for this organization.', 404);
        }

        DB::transaction(function () use ($equipment, $trailer) {
            Equipment::where('uuid', $equipment->uuid)->lockForUpdate()->firstOrFail()->update(['equipable_type' => Trailer::class, 'equipable_uuid' => $trailer->uuid]);
        });

        return response()->json([
            'status'    => 'ok',
            'message'   => 'Equipment attached to trailer.',
            'trailer'   => new TrailerResource($trailer->fresh(['equipments'])),
            'equipment' => Resolve::httpResourceForModel($equipment->fresh(['equipable'])),
        ]);
    }

    public function detachEquipment(Request $request, string $id)
    {
        $request->validate(['equipment' => ['required', 'string']]);

        $trailer   = $this->resolveTrailer($id);
        $equipment = $this->resolveEquipment($request->input('equipment'));

        if (!$trailer) {
            return response()->error('Trailer not found or not available for this organization.', 404);
        }

        if (!$equipment) {
            return response()->error('Equipment not found or not available for this organization.', 404);
        }

        if ($equipment->equipable_uuid !== $trailer->uuid || $equipment->equipable_type !== Trailer::class) {
            return response()->error('This equipment is not attached to the selected trailer.', 422);
        }

        DB::transaction(fn () => Equipment::where('uuid', $equipment->uuid)->lockForUpdate()->update(['equipable_type' => null, 'equipable_uuid' => null]));

        return response()->json([
            'status'    => 'ok',
            'message'   => 'Equipment detached from trailer.',
            'trailer'   => new TrailerResource($trailer->fresh(['equipments'])),
            'equipment' => Resolve::httpResourceForModel($equipment->fresh(['equipable'])),
        ]);
    }

    /**
     * Lifecycle statuses available to trailers, used by the console status filter.
     */
    public function statuses()
    {
        return response()->json(Trailer::STATUSES);
    }

    /**
     * Trailer classification types, used by the console type filter.
     */
    public function types()
    {
        return response()->json(Trailer::TYPES);
    }

    public function export(ExportRequest $request)
    {
        $format = $request->input('format', 'xlsx');

        return $this->downloadExport(new TrailerExport($request->array('selections')), Str::slug('trailers-' . date('Y-m-d-H:i')) . '.' . $format);
    }

    public function import(ImportRequest $request)
    {
        $count = 0;

        foreach ($request->resolveFilesFromIds() as $file) {
            $import = $this->createImport();
            $this->importFile($import, $file->path, $request->input('disk', config('filesystems.default')));
            $count += $import->imported;
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $count]);
    }

    protected function resolveTrailer(string $id): ?Trailer
    {
        return Trailer::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }

    protected function resolveVehicle(string $id): ?Vehicle
    {
        return Vehicle::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id)->orWhere('internal_id', $id))
            ->first();
    }

    protected function resolveDevice(string $id): ?Device
    {
        return Device::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }

    protected function resolveEquipment(string $id): ?Equipment
    {
        return Equipment::where('company_uuid', session('company'))
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))
            ->first();
    }

    protected function activeConnectionQuery(Trailer $trailer)
    {
        return AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid);
    }

    // @codeCoverageIgnoreStart
    // Thin spreadsheet adapter seams are exercised through export/import caller behavior.
    protected function downloadExport(TrailerExport $export, string $fileName)
    {
        return Excel::download($export, $fileName);
    }

    protected function createImport(): TrailerImport
    {
        return new TrailerImport();
    }

    protected function importFile(TrailerImport $import, string $path, string $disk): void
    {
        Excel::import($import, $path, $disk);
    }
    // @codeCoverageIgnoreEnd
}
