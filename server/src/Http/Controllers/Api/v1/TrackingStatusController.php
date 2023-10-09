<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateTrackingStatusRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateTrackingStatusRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingStatus as TrackingStatusResource;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TrackingStatusController extends Controller
{
    /**
     * Creates a new Fleetbase TrackingStatus resource.
     *
     * @param \Fleetbase\Http\Requests\CreateTrackingStatusRequest $request
     *
     * @return \Fleetbase\Http\Resources\TrackingStatus
     */
    public function create(CreateTrackingStatusRequest $request)
    {
        // get request input
        $input = $request->only(['status', 'details', 'code', 'city', 'province', 'postal_code', 'country', 'latitude', 'longitude']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // tracking number assignment
        if ($request->has('tracking_number')) {
            $input['tracking_number_uuid'] = Utils::getUuid('tracking_numbers', [
                'public_id'    => $request->input('tracking_number'),
                'company_uuid' => session('company'),
            ]);
        }

        // create the trackingStatus
        $trackingStatus = TrackingStatus::create($input);

        // response the driver resource
        return new TrackingStatusResource($trackingStatus);
    }

    /**
     * Updates a Fleetbase TrackingStatus resource.
     *
     * @param string                                               $id
     * @param \Fleetbase\Http\Requests\UpdateTrackingStatusRequest $request
     *
     * @return \Fleetbase\Http\Resources\TrackingStatus
     */
    public function update($id, UpdateTrackingStatusRequest $request)
    {
        // find for the trackingStatus
        try {
            $trackingStatus = TrackingStatus::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'TrackingStatus resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['status', 'details', 'code', 'city', 'province', 'postal_code', 'country', 'latitude', 'longitude']);

        // update the trackingStatus
        $trackingStatus->update($input);

        // response the trackingStatus resource
        return new TrackingStatusResource($trackingStatus);
    }

    /**
     * Query for Fleetbase TrackingStatus resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingStatusCollection
     */
    public function query(Request $request)
    {
        $results = TrackingStatus::queryWithRequest($request);

        return TrackingStatusResource::collection($results);
    }

    /**
     * Finds a single Fleetbase TrackingStatus resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingStatusCollection
     */
    public function find($id)
    {
        // find for the trackingStatus
        try {
            $trackingStatus = TrackingStatus::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'TrackingStatus resource not found.',
                ],
                404
            );
        }

        // response the trackingStatus resource
        return new TrackingStatusResource($trackingStatus);
    }

    /**
     * Deletes a Fleetbase TrackingStatus resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingStatusCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $trackingStatus = TrackingStatus::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'TrackingStatus resource not found.',
                ],
                404
            );
        }

        // delete the trackingStatus
        $trackingStatus->delete();

        // response the trackingStatus resource
        return new DeletedResource($trackingStatus);
    }
}
