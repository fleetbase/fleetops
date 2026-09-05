<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Events\GeofenceEntered;
use Fleetbase\FleetOps\Events\GeofenceExited;
use Fleetbase\FleetOps\Events\VehicleLocationChanged;
use Fleetbase\FleetOps\Exceptions\PublicRelationNotFoundException;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesPublicExpansions;
use Fleetbase\FleetOps\Http\Requests\CreateVehicleRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateVehicleRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Vehicle as VehicleResource;
use Fleetbase\FleetOps\Jobs\CheckGeofenceDwell;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Warranty;
use Fleetbase\FleetOps\Support\GeofenceIntersectionService;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Category;
use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    use ResolvesFleetOpsApiResources;
    use ResolvesPublicExpansions;

    /**
     * Relationships eager loaded so the public resource can report each
     * assignment as a public id without a query per vehicle.
     *
     * Loading them does not expand them: the nested object is returned only for
     * a relation the caller named in `with`, which is the shape the endpoint has
     * always had.
     */
    protected const PUBLIC_RELATIONS = ['vendor', 'category', 'warranty', 'driver', 'photo'];

    /**
     * Public expansion name => Eloquent relation name.
     */
    public const EXPANDABLE = [
        'driver'   => 'driver',
        'vendor'   => 'vendor',
        'category' => 'category',
        'warranty' => 'warranty',
        'photo'    => 'photo',
        'devices'  => 'devices',
    ];

    /**
     * Creates a new Fleetbase Vehicle resource.
     *
     * @param \Fleetbase\Http\Requests\CreateVehicleRequest $request
     *
     * @return \Fleetbase\Http\Resources\Vehicle
     */
    public function create(CreateVehicleRequest $request)
    {
        $this->applyPublicExpansions($request, static::EXPANDABLE);

        // get request input
        try {
            $input = $this->vehicleInputFromRequest($request);
        } catch (PublicRelationNotFoundException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 404);
        }

        // make sure company is set
        $input['company_uuid'] = session('company');

        // set default online
        $input = $this->withDefaultOnline($input);

        // latitude / longitude
        $input = $this->withCoordinateLocation($input, $request);

        // create the vehicle (fires 'created' event for billing resource tracking)
        $vehicle = $this->createVehicle($input);

        // driver assignment
        if ($request->exists('driver') && !empty($request->input('driver'))) {
            // set this vehicle to the driver
            try {
                $driver = $this->findDriver($request->input('driver'));
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                return $this->jsonResponse(
                    [
                        'error' => 'The driver attempted to assign this vehicle was not found.',
                    ],
                    404
                );
            }

            $vehicle->assignDriver($driver);
        }

        // response the driver resource
        return $this->vehicleResource($vehicle);
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
        $this->applyPublicExpansions($request, static::EXPANDABLE);

        // find for the vehicle
        try {
            $vehicle = $this->findVehicle($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // get request input
        try {
            $input = $this->vehicleInputFromRequest($request);
        } catch (PublicRelationNotFoundException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 404);
        }

        // latitude / longitude
        $input = $this->withCoordinateLocation($input, $request);

        // update the vehicle w/ user input
        $vehicle->fill($input);

        // if the vin has changed do another vin run
        if ($vehicle->isDirty('vin')) {
            $vehicle->applyAllDataFromVin();
        }

        // save the update
        $vehicle->save();

        if ($request->exists('driver')) {
            if (empty($request->input('driver'))) {
                $vehicle->unassignDriver();
            } else {
                try {
                    $driver = $this->findDriver($request->input('driver'));
                    $vehicle->assignDriver($driver);
                } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                    return $this->jsonResponse(
                        [
                            'error' => 'The driver attempted to assign this vehicle was not found.',
                        ],
                        404
                    );
                }
            }
        }

        // get udpated vehicle
        $vehicle = $vehicle->refresh();

        // response the vehicle resource
        return $this->vehicleResource($vehicle);
    }

    /**
     * Query for Fleetbase Vehicle resources.
     *
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function query(Request $request)
    {
        $this->applyPublicExpansions($request, static::EXPANDABLE);

        $results = $this->queryVehicles($request);

        return $this->vehicleResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Vehicle resources.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\VehicleCollection
     */
    public function find($id, ?Request $request = null)
    {
        // Falls back to the container's request rather than trusting injection:
        // the parameter carries a default, and Laravel's controller dispatcher
        // skips resolving a type-hinted dependency that has one. It arrives null,
        // the expansions are never mapped, and an unsupported name reaches
        // Eloquent — a 500 for a typo, on the one endpoint most likely to be
        // handed one.
        $request = $request instanceof Request ? $request : request();
        $this->applyPublicExpansions($request, static::EXPANDABLE);

        // find for the vehicle
        try {
            $vehicle = $this->findVehicle($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // response the vehicle resource
        return $this->vehicleResource($vehicle);
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
            $vehicle = $this->findVehicle($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Vehicle resource not found.',
                ],
                404
            );
        }

        // delete the vehicle
        $vehicle->delete();

        // response the vehicle resource
        return $this->deletedVehicleResource($vehicle);
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
            $vehicle = $this->findVehicle($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->apiError('Vehicle resource not found.', 404);
        }

        // If no lat/lng provided, maintain compatibility and just return existing driver resource
        if (empty($latitude) && empty($longitude)) {
            return $this->vehicleResource($vehicle);
        }

        $positionData = $this->positionDataFromTrackingInput($latitude, $longitude, $altitude, $heading, $speed);

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

        return $this->vehicleResource($vehicle);
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

    /**
     * The explicit public input allowlist for a vehicle.
     *
     * Mirrors every safe business field the model and the Fleet-Ops vehicle form
     * expose. Deliberately absent: `company_uuid` and the raw `*_uuid` relation
     * columns (resolved from public ids below), `public_id` / `uuid` / `_key` /
     * `slug` (generated), `avatar_url` when given as a uuid, `vin_data` (written
     * by the VIN decoder) and `telematics` (written by telematics ingestion).
     *
     * @throws PublicRelationNotFoundException
     */
    protected function vehicleInputFromRequest(Request $request): array
    {
        $input = $request->only([
            // Identity and description
            'internal_id', 'name', 'description', 'make', 'model', 'model_type', 'year',
            'trim', 'color', 'type', 'class', 'plate_number', 'vin', 'serial_number',
            'call_sign', 'fuel_card_number',
            // Measurement and operation.
            //
            // Odometer is fillable on the model and unrestricted by the request
            // rules, but was absent here — so a caller sending one received a
            // 200 and a response body that looked correct while the reading was
            // discarded. Recording mileage is the single most common write a
            // driver app makes against a vehicle.
            'odometer', 'odometer_unit', 'odometer_at_purchase', 'measurement_system',
            'fuel_type', 'fuel_volume_unit', 'online', 'status',
            'location', 'altitude', 'heading', 'speed',
            // Body, capacity and dimensions
            'transmission', 'body_type', 'body_sub_type', 'usage_type', 'ownership_type',
            'cargo_volume', 'passenger_volume', 'interior_volume', 'weight', 'width',
            'length', 'height', 'towing_capacity', 'payload_capacity', 'seating_capacity',
            'ground_clearance', 'bed_length', 'fuel_capacity',
            // Lifecycle and financing
            'financing_status', 'loan_number_of_payments', 'loan_first_payment', 'loan_amount',
            'estimated_service_life_distance_unit', 'estimated_service_life_distance',
            'estimated_service_life_months', 'insurance_value', 'depreciation_rate',
            'current_value', 'acquisition_cost', 'currency', 'purchased_at', 'lease_expires_at',
            // Regulatory and engine specifications
            'emission_standard', 'dpf_equipped', 'scr_equipped', 'gvwr', 'gcwr',
            'engine_number', 'engine_model', 'engine_make', 'engine_family',
            'engine_configuration', 'engine_displacement', 'engine_size', 'horsepower',
            'horsepower_rpm', 'torque', 'torque_rpm', 'number_of_cylinders',
            'cylinder_arrangement',
            // Structured and descriptive fields
            'specs', 'details', 'notes', 'meta',
            // Orchestrator
            'payload_capacity_volume', 'payload_capacity_pallets', 'payload_capacity_parcels',
            'skills', 'max_tasks', 'time_window_start', 'time_window_end', 'return_to_depot',
        ]);

        $this->applyPublicIdRelations($input, [
            'vendor'   => ['vendor_uuid', Vendor::class],
            'category' => ['category_uuid', Category::class],
            'warranty' => ['warranty_uuid', Warranty::class],
            'photo'    => ['photo_uuid', File::class],
        ], $request);

        return $input;
    }

    /**
     * A vehicle that does not say otherwise starts offline.
     *
     * Applied on create only. Applying it on update too meant any partial write —
     * a plate correction, an odometer reading — silently knocked the vehicle
     * offline, because the absent key was read as "set it to false" rather than
     * "leave it alone".
     */
    protected function withDefaultOnline(array $input): array
    {
        if (!isset($input['online'])) {
            $input['online'] = 0;
        }

        return $input;
    }

    protected function withCoordinateLocation(array $input, Request $request): array
    {
        if ($request->has(['latitude', 'longitude'])) {
            $input['location'] = Utils::getPointFromCoordinates($request->only(['latitude', 'longitude']));
        }

        return $input;
    }

    protected function positionDataFromTrackingInput(float $latitude, float $longitude, mixed $altitude = null, mixed $heading = null, mixed $speed = null): array
    {
        return [
            'location'  => new Point($latitude, $longitude),
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'altitude'  => $altitude,
            'heading'   => $heading,
            'speed'     => $speed,
        ];
    }

    protected function createVehicle(array $input): Vehicle
    {
        return Vehicle::create($input);
    }

    protected function findVehicle(string $id): Vehicle
    {
        return Vehicle::findRecordOrFail($id);
    }

    protected function findDriver(string $id): Driver
    {
        return Driver::findRecordOrFail($id);
    }

    protected function queryVehicles(Request $request)
    {
        return Vehicle::queryWithRequest($request, function (&$query) {
            $query->with(static::PUBLIC_RELATIONS);
        });
    }

    protected function vehicleResource(Vehicle $vehicle)
    {
        return new VehicleResource($this->withPublicRelations($vehicle));
    }

    /**
     * Load the relations the public resource reports as identifiers.
     *
     * One query per relation instead of one per relation per read, and it makes
     * an assignment made moments earlier in the same request readable back
     * immediately.
     */
    protected function withPublicRelations(Vehicle $vehicle): Vehicle
    {
        $vehicle->loadMissing(static::PUBLIC_RELATIONS);

        return $vehicle;
    }

    protected function vehicleResourceCollection($results)
    {
        return VehicleResource::collection($results);
    }

    protected function deletedVehicleResource(Vehicle $vehicle)
    {
        return new DeletedResource($vehicle);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }

    protected function apiError(string $message, int $status = 400)
    {
        return response()->apiError($message, $status);
    }
}
