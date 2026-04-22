<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVehicleRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    /**
     * Creates a new Fleetbase Vehicle resource.
     *
     * @param \Fleetbase\Http\Requests\CreateVehicleRequest $request
     *
     * @return \Fleetbase\Http\Resources\Vehicle
     */
    public function create(CreateVehicleRequest $request)
    {
        // get request input
        $input = $request->only([
            'status', 'make', 'model', 'year', 'trim', 'type', 'plate_number', 'vin',
            'meta', 'online', 'location', 'altitude', 'heading', 'speed',
            // Capacity
            'payload_capacity', 'payload_capacity_volume',
            'payload_capacity_pallets', 'payload_capacity_parcels',
            // Orchestrator constraints
            'skills', 'max_tasks', 'time_window_start', 'time_window_end', 'return_to_depot',
        ]);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // set default online
        if (!isset($input['online'])) {
            $input['online'] = 0;
        }

        // vendor assignment
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // latitude / longitude
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = Utils::getPointFromCoordinates($request->only(['latitude', 'longitude']));
        }

        // create the vehicle (fires 'created' event for billing resource tracking)
        $vehicle = Vehicle::create($input);

        // driver assignment
        if ($request->has('driver')) {
            // set this vehicle to the driver
            try {
                $driver = Driver::findRecordOrFail($request->input('driver'));
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                return response()->json(
                    [
                        'error' => 'The driver attempted to assign this vehicle was not found.',
                    ],
                    404
                );
            }

            $driver->vehicle_uuid = $vehicle->uuid;
            $driver->save();
        }

        // response the driver resource
        return new VehicleResource($vehicle);
    }

    /**
     * Updates a Fleetbase Vehicle resource.
     *
     * @param string                                        $id
     * @param \Fleetbase\Http\Requests\UpdateVehicleRequest $request
     *
     * @return \Fleetbase\Http\Resources\Vehicle
     */
    public function update($id, UpdateVehicleRequest $request)
    {
        // find for the vehicle
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only([
            'status', 'make', 'model', 'year', 'trim', 'type', 'plate_number', 'vin',
            'meta', 'location', 'online', 'altitude', 'heading', 'speed',
            // Capacity
            'payload_capacity', 'payload_capacity_volume',
            'payload_capacity_pallets', 'payload_capacity_parcels',
            // Orchestrator constraints
            'skills', 'max_tasks', 'time_window_start', 'time_window_end', 'return_to_depot',
        ]);

        // vendor assignment
        if ($request->has('vendor')) {
            $input['vendor_uuid'] = Utils::getUuid('vendors', [
                'public_id'    => $request->input('vendor'),
                'company_uuid' => session('company'),
            ]);
        }

        // set default online
        if (!isset($input['online'])) {
            $input['online'] = 0;
        }

        // latitude / longitude
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = Utils::getPointFromCoordinates($request->only(['latitude', 'longitude']));
        }

        // update the vehicle w/ user input
        $vehicle->fill($input);

        // if the vin has changed do another vin run
        if ($vehicle->isDirty('vin')) {
            $vehicle->applyAllDataFromVin();
        }

        // save the update
        $vehicle->save();

        // get udpated vehicle
        $vehicle = $vehicle->refresh();

        // response the vehicle resource
        return new VehicleResource($vehicle);
    }

    /**
     * Query for Fleetbase Vehicle resources.
     *
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function query(Request $request)
    {
        $results = Vehicle::queryWithRequest($request, function (&$query, $request) {
            if ($request->has('vendor')) {
                $query->whereHas('vendor', function ($q) use ($request) {
                    $q->where('public_id', $request->input('vendor'));
                });
            }
        });

        return VehicleResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Vehicle resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function find($id)
    {
        // find for the vehicle
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // response the vehicle resource
        return new VehicleResource($vehicle);
    }

    /**
     * Deletes a Fleetbase Vehicle resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // delete the vehicle
        $vehicle->delete();

        // response the vehicle resource
        return new DeletedResource($vehicle);
    }

    /**
     * Update vehicles geolocation data.
     *
     * @return \Illuminate\Http\Response
     */
    public function track(string $id, Request $request)
    {
        $latitude  = (float) $request->input('latitude');
        $longitude = (float) $request->input('longitude');
        $altitude  = $request->input('altitude');
        $heading   = $request->input('heading');
        $speed     = $request->input('speed');

        try {
            $vehicle = Vehicle::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->apiError('Vehicle resource not found.', 404);
        }

        // If no lat/lng provided, maintain compatibility and just return existing driver resource
        if (empty($latitude) && empty($longitude)) {
            return new VehicleResource($vehicle);
        }

        $positionData = [
            'location'  => new Point($latitude, $longitude),
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'altitude'  => $altitude,
            'heading'   => $heading,
            'speed'     => $speed,
        ];

        // Get vehicle driver
        $vehicle->loadMissing('driver');
        $driver = $vehicle->driver;
        if ($driver) {
            // Append current order to data if applicable
            $order = $driver->getCurrentOrder();
            if ($order) {
                $positionData['order_uuid'] = $order->uuid;
                // Get destination
                $destination  = $order->payload?->getPickupOrCurrentWaypoint();
                if ($destination) {
                    $positionData['destination_uuid'] = $destination->uuid;
                }
            }
        }

        $vehicle->updateQuietly($positionData);
        $vehicle->createPosition($positionData);

        broadcast(new VehicleLocationChanged($vehicle));

        try {
            $newLocation     = new Point($latitude, $longitude);
            $geofenceService = app(GeofenceIntersectionService::class);
            $this->processVehicleGeofenceCrossings($vehicle, $newLocation, $geofenceService->detectVehicleCrossings($vehicle, $newLocation));
        } catch (\Throwable $geofenceException) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($geofenceException);
            }
        }

        return new VehicleResource($vehicle);
    }

    private function processVehicleGeofenceCrossings(Vehicle $vehicle, Point $newLocation, array $crossings): void
    {
        foreach ($crossings as $crossing) {
            $geofence     = $crossing['geofence'];
            $geofenceType = $crossing['geofence_type'];

            if ($crossing['type'] === 'entered') {
                if (!$geofence->trigger_on_entry && empty($geofence->dwell_threshold_minutes)) {
                    continue;
                }

                DB::table('vehicle_geofence_states')->upsert(
                    [
                        'vehicle_uuid'  => $vehicle->uuid,
                        'geofence_uuid' => $geofence->uuid,
                        'geofence_type' => $geofenceType,
                        'is_inside'     => true,
                        'entered_at'    => now(),
                        'exited_at'     => null,
                        'dwell_job_id'  => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ],
                    ['vehicle_uuid', 'geofence_uuid'],
                    ['is_inside', 'entered_at', 'exited_at', 'dwell_job_id', 'updated_at']
                );

                if ($geofence->trigger_on_entry) {
                    event(new GeofenceEntered($vehicle, $geofence, $geofenceType, $newLocation));
                }

                if ($geofence->dwell_threshold_minutes > 0) {
                    $dwellJob = CheckGeofenceDwell::dispatch(
                        $vehicle->uuid,
                        $geofence->uuid,
                        $geofenceType,
                        'vehicle'
                    )->delay(now()->addMinutes($geofence->dwell_threshold_minutes));

                    DB::table('vehicle_geofence_states')
                        ->where('vehicle_uuid', $vehicle->uuid)
                        ->where('geofence_uuid', $geofence->uuid)
                        ->update(['dwell_job_id' => (string) $dwellJob]);
                }
            } elseif ($crossing['type'] === 'exited') {
                $state = DB::table('vehicle_geofence_states')
                    ->where('vehicle_uuid', $vehicle->uuid)
                    ->where('geofence_uuid', $geofence->uuid)
                    ->first();

                $dwellMinutes = null;
                if ($state && $state->entered_at) {
                    $dwellMinutes = (int) Carbon::parse($state->entered_at)->diffInMinutes(now());
                }

                DB::table('vehicle_geofence_states')
                    ->where('vehicle_uuid', $vehicle->uuid)
                    ->where('geofence_uuid', $geofence->uuid)
                    ->update([
                        'is_inside'    => false,
                        'exited_at'    => now(),
                        'dwell_job_id' => null,
                        'updated_at'   => now(),
                    ]);

                if ($geofence->trigger_on_exit) {
                    event(new GeofenceExited($vehicle, $geofence, $geofenceType, $newLocation, $dwellMinutes));
                }
            }
        }
    }
}
