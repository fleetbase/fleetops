<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Exceptions\PublicRelationNotFoundException;
use Fleetbase\FleetOps\Http\Controllers\Api\v1\Concerns\ResolvesFleetOpsApiResources;
use Fleetbase\FleetOps\Http\Requests\CreateFleetRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFleetRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\File;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    use ResolvesFleetOpsApiResources;

    /**
     * Relationships that are eager loaded so the public resource can report each
     * assignment as a public id without issuing a query per fleet.
     */
    protected const PUBLIC_RELATIONS = ['serviceArea', 'zone', 'vendor', 'parentFleet', 'photo'];

    /**
     * Creates a new Fleetbase Fleet resource.
     *
     * @return \Fleetbase\Http\Resources\Fleet
     */
    public function create(CreateFleetRequest $request)
    {
        try {
            $input = $this->fleetInputFromRequest($request);
        } catch (PublicRelationNotFoundException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 404);
        }

        // make sure company is set
        $input['company_uuid'] = session('company');

        // create the fleet
        $fleet = $this->createFleet($input);

        // response the fleet resource
        return $this->fleetResource($this->withPublicRelations($fleet));
    }

    /**
     * Updates a Fleetbase Fleet resource.
     *
     * @param string $id
     *
     * @return \Fleetbase\Http\Resources\Fleet
     */
    public function update($id, UpdateFleetRequest $request)
    {
        // find for the fleet
        try {
            $fleet = $this->findFleet($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Fleet resource not found.',
                ],
                404
            );
        }

        try {
            $input = $this->fleetInputFromRequest($request);
        } catch (PublicRelationNotFoundException $exception) {
            return $this->jsonResponse(['error' => $exception->getMessage()], 404);
        }

        // a fleet may not be its own parent, nor sit beneath one of its own descendants
        $hierarchyError = $this->hierarchyViolation($fleet, $input);
        if ($hierarchyError) {
            return $this->jsonResponse(['error' => $hierarchyError], 422);
        }

        // update the fleet
        $fleet->update($input);

        // response the fleet resource
        return $this->fleetResource($this->withPublicRelations($fleet));
    }

    /**
     * Query for Fleetbase Fleet resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryFleets($request);

        return $this->fleetResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Fleet resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function find($id, Request $request)
    {
        // find for the fleet
        try {
            $fleet = $this->findFleet($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Fleet resource not found.',
                ],
                404
            );
        }

        // response the fleet resource
        return $this->fleetResource($this->withPublicRelations($fleet));
    }

    /**
     * Deletes a Fleetbase Fleet resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $fleet = $this->findFleet($id);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Fleet resource not found.',
                ],
                404
            );
        }

        // delete the fleet
        $fleet->delete();

        // response the fleet resource
        return $this->deletedFleetResource($fleet);
    }

    /**
     * Adds a vehicle to a fleet.
     *
     * Idempotent: assigning a vehicle that is already a member answers exactly as
     * the first assignment did and creates no second pivot row. A membership that
     * was previously removed is restored rather than duplicated.
     *
     * @return \Illuminate\Http\Response
     */
    public function assignVehicle(string $id, string $vehicleId)
    {
        try {
            $fleet   = $this->findFleet($id);
            $vehicle = $this->findVehicle($vehicleId);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Fleet or vehicle resource not found.'], 404);
        }

        $this->assignVehicleToFleet($fleet, $vehicle);

        return $this->jsonResponse($this->vehicleMembershipPayload($fleet, $vehicle, true), 200);
    }

    /**
     * Removes a vehicle from a fleet.
     *
     * Removing a membership that is not there is a successful no-op, and removing
     * a membership never deletes the vehicle itself, its driver assignment, or its
     * membership of any other fleet.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeVehicle(string $id, string $vehicleId)
    {
        try {
            $fleet   = $this->findFleet($id);
            $vehicle = $this->findVehicle($vehicleId);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Fleet or vehicle resource not found.'], 404);
        }

        $this->removeVehicleFromFleet($fleet, $vehicle);

        return $this->jsonResponse($this->vehicleMembershipPayload($fleet, $vehicle, false), 200);
    }

    /**
     * Adds a driver to a fleet.
     *
     * @return \Illuminate\Http\Response
     */
    public function assignDriver(string $id, string $driverId)
    {
        try {
            $fleet  = $this->findFleet($id);
            $driver = $this->findDriver($driverId);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Fleet or driver resource not found.'], 404);
        }

        $this->assignDriverToFleet($fleet, $driver);

        return $this->jsonResponse($this->driverMembershipPayload($fleet, $driver, true), 200);
    }

    /**
     * Removes a driver from a fleet.
     *
     * The driver keeps its vehicle assignment and every other fleet membership.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeDriver(string $id, string $driverId)
    {
        try {
            $fleet  = $this->findFleet($id);
            $driver = $this->findDriver($driverId);
        } catch (ModelNotFoundException $exception) {
            return $this->jsonResponse(['error' => 'Fleet or driver resource not found.'], 404);
        }

        $this->removeDriverFromFleet($fleet, $driver);

        return $this->jsonResponse($this->driverMembershipPayload($fleet, $driver, false), 200);
    }

    /**
     * The explicit public input allowlist for a fleet.
     *
     * Everything outside this list is either generated (`public_id`, `slug`,
     * `uuid`, `_key`), tenancy (`company_uuid`) or a raw relation column, and is
     * resolved from a public id rather than accepted directly.
     *
     * @throws PublicRelationNotFoundException
     */
    protected function fleetInputFromRequest(Request $request): array
    {
        $input = $request->only(['name', 'color', 'task', 'status']);

        $this->applyPublicIdRelations($input, [
            'service_area' => ['service_area_uuid', ServiceArea::class],
            'zone'         => ['zone_uuid', Zone::class],
            'vendor'       => ['vendor_uuid', Vendor::class],
            'parent_fleet' => ['parent_fleet_uuid', Fleet::class],
            'photo'        => ['image_uuid', File::class],
        ], $request);

        return $input;
    }

    /**
     * Reject a parent assignment that would make the tree cyclic.
     *
     * Returns the error message to answer with, or null when the assignment is
     * sound. Walking upward from the proposed parent is enough: a cycle exists
     * exactly when this fleet is already somewhere on that chain.
     */
    protected function hierarchyViolation(Fleet $fleet, array $input): ?string
    {
        if (!array_key_exists('parent_fleet_uuid', $input) || empty($input['parent_fleet_uuid'])) {
            return null;
        }

        $parentUuid = $input['parent_fleet_uuid'];

        if ($parentUuid === $fleet->uuid) {
            return 'A fleet cannot be its own parent fleet.';
        }

        $seen     = [$parentUuid => true];
        $ancestor = $this->parentUuidOf($parentUuid);

        while ($ancestor && !isset($seen[$ancestor])) {
            if ($ancestor === $fleet->uuid) {
                return 'A fleet cannot be assigned beneath one of its own subfleets.';
            }

            $seen[$ancestor] = true;
            $ancestor        = $this->parentUuidOf($ancestor);
        }

        return null;
    }

    protected function parentUuidOf(string $uuid): ?string
    {
        return Fleet::where('uuid', $uuid)->value('parent_fleet_uuid');
    }

    protected function withPublicRelations(Fleet $fleet): Fleet
    {
        $fleet->loadMissing(static::PUBLIC_RELATIONS);

        return $fleet;
    }

    protected function assignVehicleToFleet(Fleet $fleet, Vehicle $vehicle): void
    {
        $membership = FleetVehicle::withTrashed()->firstOrNew([
            'fleet_uuid'   => $fleet->uuid,
            'vehicle_uuid' => $vehicle->uuid,
        ]);

        if ($membership->trashed()) {
            $membership->restore();

            return;
        }

        if (!$membership->exists) {
            $membership->save();
        }
    }

    protected function removeVehicleFromFleet(Fleet $fleet, Vehicle $vehicle): void
    {
        FleetVehicle::where([
            'fleet_uuid'   => $fleet->uuid,
            'vehicle_uuid' => $vehicle->uuid,
        ])->delete();
    }

    protected function assignDriverToFleet(Fleet $fleet, Driver $driver): void
    {
        $membership = FleetDriver::withTrashed()->firstOrNew([
            'fleet_uuid'  => $fleet->uuid,
            'driver_uuid' => $driver->uuid,
        ]);

        if ($membership->trashed()) {
            $membership->restore();

            return;
        }

        if (!$membership->exists) {
            $membership->save();
        }
    }

    protected function removeDriverFromFleet(Fleet $fleet, Driver $driver): void
    {
        FleetDriver::where([
            'fleet_uuid'  => $fleet->uuid,
            'driver_uuid' => $driver->uuid,
        ])->delete();
    }

    protected function vehicleMembershipPayload(Fleet $fleet, Vehicle $vehicle, bool $assigned): array
    {
        return [
            'fleet'    => $fleet->public_id,
            'vehicle'  => $vehicle->public_id,
            'assigned' => $assigned,
        ];
    }

    protected function driverMembershipPayload(Fleet $fleet, Driver $driver, bool $assigned): array
    {
        return [
            'fleet'    => $fleet->public_id,
            'driver'   => $driver->public_id,
            'assigned' => $assigned,
        ];
    }

    protected function createFleet(array $input): Fleet
    {
        return Fleet::create($input);
    }

    protected function findFleet(string $id): Fleet
    {
        return Fleet::findRecordOrFail($id);
    }

    protected function findVehicle(string $id): Vehicle
    {
        return Vehicle::findRecordOrFail($id);
    }

    protected function findDriver(string $id): Driver
    {
        return Driver::findRecordOrFail($id);
    }

    protected function queryFleets(Request $request)
    {
        return Fleet::queryWithRequest($request, function (&$query) {
            $query->with(static::PUBLIC_RELATIONS);
        });
    }

    protected function fleetResource(Fleet $fleet)
    {
        return new FleetResource($fleet);
    }

    protected function fleetResourceCollection($results)
    {
        return FleetResource::collection($results);
    }

    protected function deletedFleetResource(Fleet $fleet)
    {
        return new DeletedResource($fleet);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
