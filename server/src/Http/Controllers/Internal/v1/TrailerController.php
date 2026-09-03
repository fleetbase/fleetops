<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\TrailerExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\TrailerImport;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Device;
use Fleetbase\FleetOps\Models\Equipment;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TrailerController extends FleetOpsController
{
    public $resource = 'trailer';

    public function afterSave(Request $request, Trailer $trailer): void
    {
        $values = $request->array('trailer.custom_field_values');
        if ($values) {
            $trailer->syncCustomFieldValues($values);
        }
    }

    public function attach(Request $request, string $id)
    {
        $request->validate(['vehicle' => ['required', 'string'], 'position' => ['nullable', 'integer', 'min:1']]);
        $trailer    = Trailer::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        $vehicle    = Vehicle::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $request->vehicle)->orWhere('public_id', $request->vehicle)->orWhere('internal_id', $request->vehicle))->firstOrFail();
        $connection = DB::transaction(function () use ($trailer, $vehicle, $request) {
            $active = AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid)->lockForUpdate()->first();
            if ($active && $active->connector_uuid !== $vehicle->uuid) {
                abort(409, 'Trailer is already attached to another vehicle.');
            }
            $position    = $request->integer('position', 1);
            $positionKey = $vehicle->uuid . ':' . $position;
            if (!$active && AssetConnection::where('company_uuid', session('company'))->where('active_connector_position', $positionKey)->lockForUpdate()->exists()) {
                abort(409, 'Another trailer already occupies this towing position.');
            }

            return $active ?: AssetConnection::create(['company_uuid' => session('company'), 'connector_type' => Vehicle::class, 'connector_uuid' => $vehicle->uuid, 'connected_type' => Trailer::class, 'connected_uuid' => $trailer->uuid, 'active_connected_uuid' => $trailer->uuid, 'active_connector_position' => $positionKey, 'relationship_type' => 'towing', 'position' => $position, 'connected_at' => now(), 'source' => 'manual', 'created_by_uuid' => session('user'), 'updated_by_uuid' => session('user')]);
        }, 3);

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['currentConnection.vehicle']), 'connection' => $connection->load(['vehicle', 'trailer'])]);
    }

    public function detach(string $id)
    {
        $trailer = Trailer::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        DB::transaction(fn () => AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid)->lockForUpdate()->update(['active_connected_uuid' => null, 'active_connector_position' => null, 'disconnected_at' => now(), 'updated_by_uuid' => session('user'), 'updated_at' => now()]));

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['currentConnection.vehicle'])]);
    }

    public function attachDevice(Request $request, string $id)
    {
        $request->validate(['device' => ['required', 'string']]);
        $trailer = Trailer::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        $device  = Device::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $request->device)->orWhere('public_id', $request->device))->firstOrFail();
        $device->attachTo($trailer);

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['devices']), 'device' => $device->fresh(['attachable'])]);
    }

    public function detachDevice(Request $request, string $id)
    {
        $request->validate(['device' => ['required', 'string']]);
        $trailer = Trailer::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        $device  = Device::where('company_uuid', session('company'))->where(fn ($q) => $q->where('uuid', $request->device)->orWhere('public_id', $request->device))->firstOrFail();
        if ($device->attachable_uuid !== $trailer->uuid || $device->attachable_type !== Trailer::class) {
            abort(422, 'Device is not attached to this trailer.');
        }
        $device->detach();

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['devices']), 'device' => $device->fresh(['attachable'])]);
    }

    public function attachEquipment(Request $request, string $id)
    {
        $request->validate(['equipment' => ['required', 'string']]);
        $trailer   = Trailer::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        $equipment = Equipment::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $request->equipment)->orWhere('public_id', $request->equipment))->firstOrFail();
        DB::transaction(function () use ($equipment, $trailer) {
            $locked = Equipment::where('uuid', $equipment->uuid)->lockForUpdate()->firstOrFail();
            $locked->update(['equipable_type' => Trailer::class, 'equipable_uuid' => $trailer->uuid]);
        });

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['equipments']), 'equipment' => $equipment->fresh(['equipable'])]);
    }

    public function detachEquipment(Request $request, string $id)
    {
        $request->validate(['equipment' => ['required', 'string']]);
        $trailer   = Trailer::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $id)->orWhere('public_id', $id))->firstOrFail();
        $equipment = Equipment::where('company_uuid', session('company'))->where(fn ($query) => $query->where('uuid', $request->equipment)->orWhere('public_id', $request->equipment))->firstOrFail();
        if ($equipment->equipable_uuid !== $trailer->uuid || $equipment->equipable_type !== Trailer::class) {
            abort(422, 'Equipment is not attached to this trailer.');
        }
        DB::transaction(fn () => Equipment::where('uuid', $equipment->uuid)->lockForUpdate()->update(['equipable_type' => null, 'equipable_uuid' => null]));

        return response()->json(['status' => 'ok', 'trailer' => $trailer->fresh(['equipments']), 'equipment' => $equipment->fresh(['equipable'])]);
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
