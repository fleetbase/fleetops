<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateServiceAreaRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateServiceAreaRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\ServiceArea as ServiceAreaResource;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Http\Request;

class ServiceAreaController extends Controller
{
    /**
     * Creates a new Fleetbase ServiceArea resource.
     *
     * @param \Fleetbase\Http\Requests\CreateServiceAreaRequest $request
     *
     * @return \Fleetbase\Http\Resources\ServiceArea
     */
    public function create(CreateServiceAreaRequest $request)
    {
        // get request input
        $input = $this->serviceAreaInputFromRequest($request);

        // get radius for creating service area border - default to 500 meters
        $radius = $this->radiusFromRequest($request);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // if parent service area set
        if ($request->filled('parent')) {
            $input['parent_uuid'] = $this->serviceAreaUuid($request->input('parent'), [
                'public_id'    => $request->input('parent'),
                'company_uuid' => session('company'),
            ]);
        }

        // if latitude and longitude is provided
        if ($request->has(['latitude', 'longitude'])) {
            // create a polygon given the radius
            $latitude  = $request->input('latitude');
            $longitude = $request->input('longitude');
            $point     = new Point($latitude, $longitude);

            if ($point instanceof Point) {
                $input['border'] = $this->createBorderFromPoint($point, $radius);
            }
        }

        // if a location is provided
        if ($request->has('location')) {
            $location = $request->input('location');
            $point    = $this->pointFromLocation($location);

            if ($point instanceof Point) {
                $input['border'] = $this->createBorderFromPoint($point, $radius);
            }
        }

        // create the serviceArea
        try {
            $serviceArea = $this->createServiceArea($input);
            $serviceArea->refresh();
        } catch (\Throwable $e) {
            $this->logServiceAreaCreateFailure($e);

            return $this->apiError('Failed to create service area.');
        }

        // response the driver resource
        return $this->serviceAreaResource($serviceArea);
    }

    /**
     * Updates a Fleetbase ServiceArea resource.
     *
     * @param string                                            $id
     * @param \Fleetbase\Http\Requests\UpdateServiceAreaRequest $request
     *
     * @return \Fleetbase\Http\Resources\ServiceArea
     */
    public function update($id, UpdateServiceAreaRequest $request)
    {
        // find for the serviceArea
        try {
            $serviceArea = $this->findServiceAreaRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'ServiceArea resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $this->serviceAreaInputFromRequest($request);

        // get radius for creating service area border - default to 500 meters
        $radius = $this->radiusFromRequest($request);

        // if parent service area set
        if ($request->filled('parent')) {
            $input['parent_uuid'] = $this->serviceAreaUuid($request->input('parent'), [
                'public_id'    => $request->input('parent'),
                'company_uuid' => session('company'),
            ]);
        }

        // if latitude and longitude is provided
        if ($request->has(['latitude', 'longitude'])) {
            // create a polygon given the radius
            $latitude  = $request->input('latitude');
            $longitude = $request->input('longitude');
            $point     = new Point($latitude, $longitude);

            if ($point instanceof Point) {
                $input['border'] = $this->createBorderFromPoint($point, $radius);
            }
        }

        // if a location is provided
        if ($request->has('location')) {
            $location = $request->input('location');
            $point    = $this->pointFromLocation($location);

            if ($point instanceof Point) {
                $input['border'] = $this->createBorderFromPoint($point, $radius);
            }
        }

        // update the serviceArea
        $serviceArea->update($input);
        $serviceArea->refresh();

        // response the serviceArea resource
        return $this->serviceAreaResource($serviceArea);
    }

    /**
     * Query for Fleetbase ServiceArea resources.
     *
     * @return \Fleetbase\Http\Resources\ServiceAreaCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryServiceAreas($request);

        return $this->serviceAreaResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase ServiceArea resources.
     *
     * @return \Fleetbase\Http\Resources\ServiceAreaCollection
     */
    public function find($id, Request $request)
    {
        // find for the serviceArea
        try {
            $serviceArea = $this->findServiceAreaRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'ServiceArea resource not found.',
                ],
                404
            );
        }

        // response the serviceArea resource
        return $this->serviceAreaResource($serviceArea);
    }

    /**
     * Deletes a Fleetbase ServiceArea resources.
     *
     * @return \Fleetbase\Http\Resources\ServiceAreaCollection
     */
    public function delete($id, Request $request)
    {
        // find for the driver
        try {
            $serviceArea = $this->findServiceAreaRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'ServiceArea resource not found.',
                ],
                404
            );
        }

        // delete the serviceArea
        $serviceArea->delete();

        // response the serviceArea resource
        return $this->deletedServiceAreaResource($serviceArea);
    }

    protected function serviceAreaInputFromRequest(Request $request): array
    {
        return $request->only(['name', 'type', 'status', 'country', 'border', 'color', 'stroke_color', 'trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'speed_limit_kmh']);
    }

    protected function radiusFromRequest(Request $request): int
    {
        return (int) $request->input('radius', 500);
    }

    protected function createBorderFromPoint(Point $point, int $radius)
    {
        return ServiceArea::createMultiPolygonFromPoint($point, $radius);
    }

    protected function serviceAreaUuid(string $publicId, array $where): ?string
    {
        return Utils::getUuid('service_areas', $where);
    }

    protected function pointFromLocation(mixed $location)
    {
        return Utils::getPointFromMixed($location);
    }

    protected function createServiceArea(array $input): ServiceArea
    {
        return ServiceArea::create($input);
    }

    protected function findServiceAreaRecord(string $id): ServiceArea
    {
        return ServiceArea::findRecordOrFail($id);
    }

    protected function queryServiceAreas(Request $request)
    {
        return ServiceArea::queryWithRequest($request);
    }

    protected function serviceAreaResource(ServiceArea $serviceArea)
    {
        return new ServiceAreaResource($serviceArea);
    }

    protected function serviceAreaResourceCollection($results)
    {
        return ServiceAreaResource::collection($results);
    }

    protected function deletedServiceAreaResource(ServiceArea $serviceArea)
    {
        return new DeletedResource($serviceArea);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }

    protected function apiError(string $message)
    {
        return response()->apiError($message);
    }

    protected function logServiceAreaCreateFailure(\Throwable $e): void
    {
        logger()->error('Unable to create service area.', ['error' => $e->getMessage()]);
    }
}
