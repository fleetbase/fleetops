<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateZoneRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateZoneRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    /**
     * Creates a new Fleetbase Zone resource.
     *
     * @param \Fleetbase\Http\Requests\CreateZoneRequest $request
     *
     * @return \Fleetbase\Http\Resources\Zone
     */
    public function create(CreateZoneRequest $request)
    {
        // get request input
        $input = $request->only(['name', 'boundary', 'status', 'description', 'color', 'stroke_color']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = Utils::getUuid('service_areas', [
                'public_id'    => $request->input('service_area'),
                'company_uuid' => session('company'),
            ]);
        }

        // create the zone
        $zone = Zone::create($input);

        // response the driver resource
        return new ZoneResource($zone);
    }

    /**
     * Updates a Fleetbase Zone resource.
     *
     * @param string                                     $id
     * @param \Fleetbase\Http\Requests\UpdateZoneRequest $request
     *
     * @return \Fleetbase\Http\Resources\Zone
     */
    public function update($id, UpdateZoneRequest $request)
    {
        // find for the zone
        try {
            $zone = Zone::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['name', 'boundary', 'status', 'description', 'color', 'stroke_color']);

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = Utils::getUuid('service_areas', [
                'public_id'    => $request->input('service_area'),
                'company_uuid' => session('company'),
            ]);
        }

        // update the zone
        $zone->update($input);

        // response the zone resource
        return new ZoneResource($zone);
    }

    /**
     * Query for Fleetbase Zone resources.
     *
     * @return \Fleetbase\Http\Resources\ZoneCollection
     */
    public function query(Request $request)
    {
        $results = Zone::queryWithRequest($request);

        return ZoneResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Zone resources.
     *
     * @return \Fleetbase\Http\Resources\ZoneCollection
     */
    public function find($id)
    {
        // find for the zone
        try {
            $zone = Zone::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // response the zone resource
        return new ZoneResource($zone);
    }

    /**
     * Deletes a Fleetbase Zone resources.
     *
     * @return \Fleetbase\Http\Resources\ZoneCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $zone = Zone::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // delete the zone
        $zone->delete();

        // response the zone resource
        return new DeletedResource($zone);
    }
}
