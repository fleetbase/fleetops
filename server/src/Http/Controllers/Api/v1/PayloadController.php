<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreatePayloadRequest;
use Fleetbase\FleetOps\Http\Requests\UpdatePayloadRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Payload as PayloadResource;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

// use Fleetbase\FleetOps\Support\Utils;

class PayloadController extends Controller
{
    /**
     * Creates a new Fleetbase Payload resource.
     *
     * @param \Fleetbase\Http\Requests\CreatePayloadRequest $request
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function create(CreatePayloadRequest $request)
    {
        // get request input
        $input = $request->only(['pickup', 'dropoff', 'type', 'return', 'waypoints', 'customer', 'meta', 'cod_amount', 'cod_currency', 'cod_payment_method']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // create the payload
        $payload = new Payload($input);

        // set pickup point
        $payload->setPickup($request->input('pickup'));

        // set dropoff point
        $payload->setDropoff($request->input('dropoff'));

        // set return point
        $payload->setReturn($request->input('return'));

        // save payload
        $payload->save();

        // set waypoints
        if ($request->has('waypoints') && is_array($request->input('waypoints'))) {
            foreach ($request->input('waypoints') as $index => $place) {
                // get place
                $id = Place::createFromMixed($place);

                // create waypoint
                Waypoint::create([
                    'place_uuid'   => $id,
                    'payload_uuid' => $payload->uuid,
                    'order'        => $index,
                ]);
            }
        }

        // response the driver resource
        return new PayloadResource($payload);
    }

    /**
     * Updates a Fleetbase Payload resource.
     *
     * @param string                                        $id
     * @param \Fleetbase\Http\Requests\UpdatePayloadRequest $request
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function update($id, UpdatePayloadRequest $request)
    {
        // find for the payload
        try {
            $payload = Payload::findRecordOrFail($id, ['waypoints']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $request->only(['pickup', 'dropoff', 'type', 'return', 'waypoints', 'customer', 'meta', 'cod_amount', 'cod_currency', 'cod_payment_method']);

        // pickup assignment
        if ($request->has('pickup')) {
            $payload->setPickup($request->input('pickup'));
        }

        // dropoff assignment
        if ($request->has('dropoff')) {
            $payload->setDropoff($request->input('dropoff'));
        }

        // return assignment
        if ($request->has('return')) {
            $payload->setReturn($request->input('return'));
        }

        // set waypoints
        if ($request->has('waypoints') && is_array($request->input('waypoints'))) {
            $waypointCount = $payload->waypoints->count() ?? 0;

            foreach ($request->input('waypoints') as $index => $place) {
                // get place
                $id = Place::createFromMixed($place);

                // create waypoint
                Waypoint::create([
                    'place_uuid'   => $id,
                    'payload_uuid' => $payload->uuid,
                    'order'        => $index + $waypointCount,
                ]);
            }
        }

        // update the payload
        $payload->fill($input);

        // save the payload
        $payload->save();

        // response the payload resource
        return new PayloadResource($payload);
    }

    /**
     * Query for Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\PayloadCollection
     */
    public function query(Request $request)
    {
        $results = Payload::queryWithRequest($request);

        return PayloadResource::collection($results);
    }

    /**
     * Finds a single Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function find($id, Request $request)
    {
        // find for the payload
        try {
            $payload = Payload::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // response the payload resource
        return new PayloadResource($payload);
    }

    /**
     * Deletes a Fleetbase Payload resources.
     *
     * @return \Fleetbase\Http\Resources\Payload
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $payload = Payload::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return response()->json(
                [
                    'error' => 'Payload resource not found.',
                ],
                404
            );
        }

        // delete the payload
        $payload->delete();

        // response the payload resource
        return new DeletedResource($payload);
    }
}
