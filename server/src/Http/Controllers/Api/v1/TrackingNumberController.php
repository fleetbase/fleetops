<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateTrackingNumberRequest;
use Fleetbase\FleetOps\Http\Requests\DecodeTrackingNumberQR;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingNumber as TrackingNumberResource;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TrackingNumberController extends Controller
{
    /**
     * Creates a new Fleetbase TrackingNumber resource.
     *
     * @param \Fleetbase\Http\Requests\CreateTrackingNumberRequest $request
     *
     * @return \Fleetbase\Http\Resources\TrackingNumber
     */
    public function create(CreateTrackingNumberRequest $request)
    {
        // get request input
        $input = $request->only(['region', 'type']);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // owner assignment
        if ($request->has('owner')) {
            $owner = $this->getOwnerUuid(
                ['orders', 'entities'],
                [
                    'public_id'    => $request->input('owner'),
                    'company_uuid' => session('company'),
                ],
                [
                    'with_table' => true,
                ]
            );

            if (is_array($owner)) {
                $input['owner_uuid']       = Utils::get($owner, 'uuid');
                $input['owner_type']       = Utils::getModelClassName(Utils::get($owner, 'table'));
            }
        }

        // create the trackingNumber
        $trackingNumber = $this->createTrackingNumber($input);

        // response the driver resource
        return $this->trackingNumberResource($trackingNumber);
    }

    /**
     * Query for Fleetbase TrackingNumber resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryTrackingNumbers($request);

        return $this->trackingNumberResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase TrackingNumber resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function find($id)
    {
        // find for the trackingNumber
        try {
            $trackingNumber = $this->findTrackingNumber($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'TrackingNumber resource not found.',
                ],
                404
            );
        }

        // response the trackingNumber resource
        return $this->trackingNumberResource($trackingNumber);
    }

    /**
     * Deletes a Fleetbase TrackingNumber resources.
     *
     * @return \Fleetbase\Http\Resources\TrackingNumberCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $trackingNumber = $this->findTrackingNumber($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'TrackingNumber resource not found.',
                ],
                404
            );
        }

        // delete the trackingNumber
        $trackingNumber->delete();

        // response the trackingNumber resource
        return $this->deletedTrackingNumberResource($trackingNumber);
    }

    /**
     * Take the uuid value of an entity QR code and return the object.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function fromQR(DecodeTrackingNumberQR $request)
    {
        // validate request inputs
        $code = $request->input('code');

        // get the model of from the code
        $model = $this->findQrModel(['entities', 'orders'], ['uuid' => $code]);

        // if no model response with error
        if (!$model) {
            return $this->jsonResponse(
                [
                    'error' => 'Unable to find QR code value',
                ],
                400
            );
        }

        // get the model class name
        return $this->qrModelResource($model);
    }

    protected function getOwnerUuid(array $tables, array $where, array $options)
    {
        return Utils::getUuid($tables, $where, $options);
    }

    protected function createTrackingNumber(array $input): TrackingNumber
    {
        return TrackingNumber::create($input);
    }

    protected function queryTrackingNumbers(Request $request)
    {
        return TrackingNumber::queryWithRequest($request);
    }

    protected function findTrackingNumber(string $id): TrackingNumber
    {
        return TrackingNumber::findTrackingOrFail($id);
    }

    protected function trackingNumberResource(TrackingNumber $trackingNumber)
    {
        return new TrackingNumberResource($trackingNumber);
    }

    protected function trackingNumberResourceCollection($results)
    {
        return TrackingNumberResource::collection($results);
    }

    protected function deletedTrackingNumberResource(TrackingNumber $trackingNumber)
    {
        return new DeletedResource($trackingNumber);
    }

    protected function findQrModel(array $tables, array $where)
    {
        return Utils::findModel($tables, $where);
    }

    protected function qrModelResource($model)
    {
        $modelType         = class_basename($model);
        $resourceNamespace = '\\Fleetbase\\Http\\Resources\\v1\\' . $modelType;

        return new $resourceNamespace($model);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
