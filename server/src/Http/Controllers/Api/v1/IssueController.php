<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateIssueRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateIssueRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Fleet as FleetResource;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as DeletedIssue;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    /**
     * Creates a new Fleetbase Issue resource.
     *
     * @param \Fleetbase\Http\Requests\CreateIssueRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function createRecord(CreateIssueRequest $request)
    {
        // get request input
        $input = $request->only([
            'driver',
            'location',
            'category',
            'type',
            'report',
            'priority',
        ]);

        // Find driver who is reporting
        try {
            $driver = Driver::findRecordOrFail($request->input('driver'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Driver reporting issue not found.',
                ],
                404
            );
        }

        // get the user uuid
        $input['driver_uuid'] = $driver->uuid;
        $input['reported_by_uuid'] = $driver->user_uuid;
        $input['vehicle_uuid'] = $driver->vehicle_uuid;

        // create the entity
        $entity = Issue::create($input);

        // response the driver resource
        return new IssueResource($entity);
    }

       /**
     * Updates new Fleetbase Issue resource.
     * 
     * @param string                                      $id
     * @param \Fleetbase\Http\Requests\UpdateIssueRequest $request
     *
     * @return \Fleetbase\Http\Resources\Issue
     */
    public function update($id, UpdateIssueRequest $request)
    {
        // find for the fleet
        try {
            $fleet = Issue::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
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
            $input['service_area_uuid'] = Utils::getUuid('service_areas', [
                'public_id'    => $request->input('service_area'),
                'company_uuid' => session('company'),
            ]);
        }

        // update the fleet
        $fleet->update($input);

        // response the fleet resource
        return new FleetResource($fleet);
    }


    /**
     * Query for Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function queryRecord(Request $request)
    {
        $results = Issue::queryWithRequest($request);

        return IssueResource::collection($results);
    }


        /**
     * Deletes a Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $issue = Issue::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Issue resource not found.',
                ],
                404
            );
        }

        // delete the contact
        $issue->delete();

        // response the contact resource
        return new DeletedIssue($issue);
    }
}