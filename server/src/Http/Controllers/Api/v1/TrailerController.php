<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\TrailerLocationChanged;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateTrailerRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateTrailerRequest;
use Fleetbase\FleetOps\Http\Resources\v1\AssetConnection as AssetConnectionResource;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Trailer as TrailerResource;
use Fleetbase\FleetOps\Models\AssetConnection;
use Fleetbase\FleetOps\Models\Trailer;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrailerController extends Controller
{
    use ResolvesFleetOpsApiResources;

    private const RELATIONS = ['category', 'vendor', 'warranty', 'photo', 'currentConnection.vehicle', 'connections.vehicle', 'devices', 'equipments'];

    public function create(CreateTrailerRequest $request)
    {
        $this->rejectUuidIdentifiers($request);
        $input                 = $this->input($request);
        $input['company_uuid'] = session('company');

        return new TrailerResource($this->load(Trailer::create($input)));
    }

    public function query(Request $request)
    {
        $this->rejectUuidIdentifiers($request);

        return TrailerResource::collection($this->queryTrailers($request, fn (&$query) => $query->with(self::RELATIONS)->withCount(['devices', 'equipments'])));
    }

    public function find(string $id)
    {
        try {
            return new TrailerResource($this->load($this->resolveModel(Trailer::class, $id)));
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }
    }

    public function update(string $id, UpdateTrailerRequest $request)
    {
        $this->rejectUuidIdentifiers($request);
        try {
            $trailer = $this->resolveModel(Trailer::class, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }
        $trailer->update($this->input($request));

        return new TrailerResource($this->load($trailer->refresh()));
    }

    public function delete(string $id)
    {
        try {
            $trailer = $this->resolveModel(Trailer::class, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }
        if (AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid)->exists()) {
            return response()->json(['error' => 'Detach the trailer before deleting it.'], 409);
        }
        $trailer->delete();

        return new DeletedResource($trailer);
    }

    public function track(string $id, Request $request)
    {
        $request->validate([
            'latitude'    => ['required', 'numeric', 'between:-90,90'],
            'longitude'   => ['required', 'numeric', 'between:-180,180'],
            'observed_at' => ['nullable', 'date'],
            'speed'       => ['nullable', 'numeric', 'min:0'],
            'heading'     => ['nullable', 'numeric', 'between:0,360'],
            'altitude'    => ['nullable', 'numeric'],
            'odometer'    => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $resolved = $this->resolveModel(Trailer::class, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }

        [$trailer, $changed] = DB::transaction(function () use ($resolved, $request) {
            $trailer    = Trailer::where('company_uuid', session('company'))->where('uuid', $resolved->uuid)->lockForUpdate()->firstOrFail();
            $observedAt = $request->date('observed_at') ?? now();
            $currentAt  = data_get($trailer->telematics, 'last_event_at');
            if ($currentAt && $observedAt->lt(Carbon::parse($currentAt))) {
                return [$trailer, false];
            }

            $snapshot = ['location' => new Point((float) $request->latitude, (float) $request->longitude), 'online' => true, 'last_online_at' => $observedAt];
            foreach (['speed', 'heading', 'altitude', 'odometer'] as $field) {
                if ($request->exists($field)) {
                    $snapshot[$field] = $request->input($field);
                }
            }
            $snapshot['telematics'] = array_merge($trailer->telematics ?? [], ['last_event_at' => $observedAt->toISOString(), 'last_provider' => 'public_api', 'last_telemetry_data' => $request->only(['speed', 'heading', 'altitude', 'odometer'])]);
            $trailer->update($snapshot);
            $trailer->createPosition($request->only(['latitude', 'longitude', 'speed', 'heading', 'altitude']));

            return [$trailer, true];
        }, 3);

        if ($changed) {
            broadcast(new TrailerLocationChanged($trailer, ['source' => 'public_api']));
        }

        return new TrailerResource($this->load($trailer));
    }

    public function attach(string $id, Request $request)
    {
        $this->rejectUuidIdentifiers($request);
        $request->validate(['vehicle' => ['required', 'string'], 'connected_at' => ['nullable', 'date'], 'source' => ['nullable', 'string'], 'position' => ['nullable', 'integer', 'min:1']]);
        try {
            $result = DB::transaction(function () use ($id, $request) {
                $trailer = Trailer::where('company_uuid', session('company'))->where('public_id', $id)->lockForUpdate()->firstOrFail();
                $vehicle = Vehicle::where('company_uuid', session('company'))->where(function ($q) use ($request) { $q->where('public_id', $request->vehicle)->orWhere('internal_id', $request->vehicle); })->lockForUpdate()->firstOrFail();
                $active  = AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid)->lockForUpdate()->first();
                if ($active) {
                    if ($active->connector_uuid === $vehicle->uuid) {
                        return $active->load(['vehicle', 'trailer']);
                    }
                    abort(409, 'Trailer is already attached to another vehicle. Detach it before moving.');
                }
                $position    = $request->integer('position', 1);
                $positionKey = $vehicle->uuid . ':' . $position;
                if (AssetConnection::where('company_uuid', session('company'))->where('active_connector_position', $positionKey)->lockForUpdate()->exists()) {
                    abort(409, 'Another trailer already occupies this towing position.');
                }

                return AssetConnection::create([
                    'company_uuid'              => session('company'), 'connector_type' => Vehicle::class, 'connector_uuid' => $vehicle->uuid,
                    'connected_type'            => Trailer::class, 'connected_uuid' => $trailer->uuid, 'active_connected_uuid' => $trailer->uuid,
                    'active_connector_position' => $positionKey, 'relationship_type' => 'towing', 'position' => $position, 'connected_at' => $request->date('connected_at') ?? now(),
                    'source'                    => $request->input('source', 'manual'), 'created_by_uuid' => session('user'), 'updated_by_uuid' => session('user'),
                ])->load(['vehicle', 'trailer']);
            }, 3);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer or vehicle resource not found.'], 404);
        }

        return new AssetConnectionResource($result);
    }

    public function detach(string $id, Request $request)
    {
        $request->validate(['disconnected_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string']]);
        try {
            $connection = DB::transaction(function () use ($id, $request) {
                $trailer = Trailer::where('company_uuid', session('company'))->where('public_id', $id)->lockForUpdate()->firstOrFail();
                $active  = AssetConnection::where('company_uuid', session('company'))->where('active_connected_uuid', $trailer->uuid)->lockForUpdate()->first();
                if (!$active) {
                    return null;
                }
                $active->update(['disconnected_at' => $request->date('disconnected_at') ?? now(), 'active_connected_uuid' => null, 'active_connector_position' => null, 'notes' => $request->input('notes', $active->notes), 'updated_by_uuid' => session('user')]);

                return $active->load(['vehicle', 'trailer']);
            }, 3);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }

        return response()->json(['status' => 'ok', 'connection' => $connection ? new AssetConnectionResource($connection) : null]);
    }

    public function connections(string $id)
    {
        try {
            $trailer = $this->resolveModel(Trailer::class, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Trailer resource not found.'], 404);
        }

        return AssetConnectionResource::collection($trailer->connections()->with(['vehicle', 'trailer'])->get());
    }

    public function vehicleTrailers(string $id)
    {
        try {
            $vehicle = $this->resolveModel(Vehicle::class, $id);
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'Vehicle resource not found.'], 404);
        }
        $trailers = Trailer::whereHas('currentConnection', fn ($q) => $q->where('connector_uuid', $vehicle->uuid))->with(self::RELATIONS)->withCount(['devices', 'equipments'])->get();

        return TrailerResource::collection($trailers);
    }

    protected function input(Request $request): array
    {
        $input = $request->only(['name', 'description', 'code', 'type', 'body_type', 'status', 'vin', 'plate_number', 'serial_number', 'make', 'model', 'year', 'color', 'usage_type', 'measurement_system', 'odometer', 'odometer_unit', 'ownership_type', 'purchased_at', 'lease_expires_at', 'financing_status', 'currency', 'acquisition_cost', 'current_value', 'insurance_value', 'depreciation_rate', 'length', 'width', 'height', 'tare_weight', 'gvwr', 'payload_capacity', 'cargo_volume', 'axle_count', 'tire_count', 'door_count', 'coupling_type', 'brake_type', 'abs_equipped', 'ebs_equipped', 'refrigerated', 'temperature_min', 'temperature_max', 'reefer_engine_hours', 'capacity', 'specs', 'attributes', 'notes']);
        if ($request->filled(['latitude', 'longitude'])) {
            $input['location'] = new Point((float) $request->latitude, (float) $request->longitude);
        } elseif ($request->filled('location')) {
            $input['location'] = Utils::getPointFromCoordinates($request->location);
        }
        $this->applyPublicIdRelation($input, 'category', 'category_uuid', Category::class, $request);
        $this->applyPublicIdRelation($input, 'vendor', 'vendor_uuid', Vendor::class, $request);
        $this->applyPublicIdRelation($input, 'warranty', 'warranty_uuid', Warranty::class, $request);
        $this->applyPublicIdRelation($input, 'photo', 'photo_uuid', File::class, $request);

        return $input;
    }

    protected function queryTrailers(Request $request, callable $callback)
    {
        // @codeCoverageIgnoreStart
        return Trailer::queryWithRequest($request, $callback);
        // @codeCoverageIgnoreEnd
    }

    private function load(Trailer $trailer): Trailer
    {
        return $trailer->load(self::RELATIONS)->loadCount(['devices', 'equipments']);
    }
}
