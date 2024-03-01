<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\Http\Controllers\Controller;

class IssueController extends Controller
{
    /**
     * Creates a new Fleetbase Issue resource.
     *
     * @param \Fleetbase\Http\Requests\CreateIssueRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function create(CreateIssueRequest $request)
    {
        // get request input
        $input = $request->only([
            'driver',
            'location',
            'category',
            'type',
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
}
