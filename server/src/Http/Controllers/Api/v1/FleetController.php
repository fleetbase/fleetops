<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateFleetRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateFleetRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    /**
     * Creates a new Fleetbase Fleet resource.
     *
     * @param \Fleetbase\Http\Requests\CreateFleetRequest $request
     *
     * @return \Fleetbase\Http\Resources\Fleet
     */
    public function create(CreateFleetRequest $request)
    {
        // get request input
        $input = $request->only(['name']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = $this->getServiceAreaUuid('service_areas', [
                'public_id'    => $request->input('service_area'),
                'company_uuid' => session('company'),
            ]);
        }

        // create the fleet
        $fleet = $this->createFleet($input);

        // response the driver resource
        return $this->fleetResource($fleet);
    }

    /**
     * Updates a Fleetbase Fleet resource.
     *
     * @param string                                      $id
     * @param \Fleetbase\Http\Requests\UpdateFleetRequest $request
     *
     * @return \Fleetbase\Http\Resources\Fleet
     */
    public function update($id, UpdateFleetRequest $request)
    {
        // find for the fleet
        try {
            $fleet = $this->findFleet($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Fleet resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['name']);

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = $this->getServiceAreaUuid('service_areas', [
                'public_id'    => $request->input('service_area'),
                'company_uuid' => session('company'),
            ]);
        }

        // update the fleet
        $fleet->update($input);

        // response the fleet resource
        return $this->fleetResource($fleet);
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Fleet resource not found.',
                ],
                404
            );
        }

        // response the fleet resource
        return $this->fleetResource($fleet);
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
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

    protected function getServiceAreaUuid(string $table, array $where): ?string
    {
        return Utils::getUuid($table, $where);
    }

    protected function createFleet(array $input): Fleet
    {
        return Fleet::create($input);
    }

    protected function findFleet(string $id): Fleet
    {
        return Fleet::findRecordOrFail($id);
    }

    protected function queryFleets(Request $request)
    {
        return Fleet::queryWithRequest($request);
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
