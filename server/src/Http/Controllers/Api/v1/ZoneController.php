<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateZoneRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateZoneRequest;
use Fleetbase\FleetOps\Http\Resources\v1\DeletedResource;
use Fleetbase\FleetOps\Http\Resources\v1\Zone as ZoneResource;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
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
        $input = $this->zoneInputFromRequest($request);

        // get radius for creating zone border - default to 500 meters
        $radius = $this->radiusFromRequest($request);

        // make sure company is set
        $input['company_uuid'] = session('company');

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = $this->serviceAreaUuid($request->input('service_area'), [
                'public_id'    => $request->input('service_area'),
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

        /**
         * @todo if missing location, latitude, longitude and border
         * then create a zone from the center of the service area provided
         */

        // create the zone
        $zone = $this->createZone($input);
        $zone->refresh();

        // response the zone resource
        return $this->zoneResource($zone);
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
            $zone = $this->findZoneRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // get request input
        $input = $this->zoneInputFromRequest($request);

        // get radius for creating zone border - default to 500 meters
        $radius = $this->radiusFromRequest($request);

        // service area assignment
        if ($request->has('service_area')) {
            $input['service_area_uuid'] = $this->serviceAreaUuid($request->input('service_area'), [
                'public_id'    => $request->input('service_area'),
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

        // update the zone
        $zone->update($input);
        $zone->refresh();

        // response the zone resource
        return $this->zoneResource($zone);
    }

    /**
     * Query for Fleetbase Zone resources.
     *
     * @return \Fleetbase\Http\Resources\ZoneCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryZones($request);

        return $this->zoneResourceCollection($results);
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
            $zone = $this->findZoneRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // response the zone resource
        return $this->zoneResource($zone);
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
            $zone = $this->findZoneRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Zone resource not found.',
                ],
                404
            );
        }

        // delete the zone
        $zone->delete();

        // response the zone resource
        return $this->deletedZoneResource($zone);
    }

    protected function zoneInputFromRequest(Request $request): array
    {
        return $request->only(['name', 'border', 'status', 'description', 'color', 'stroke_color', 'trigger_on_entry', 'trigger_on_exit', 'dwell_threshold_minutes', 'speed_limit_kmh']);
    }

    protected function radiusFromRequest(Request $request): int
    {
        return (int) $request->input('radius', 500);
    }

    protected function serviceAreaUuid(string $publicId, array $where): ?string
    {
        return Utils::getUuid('service_areas', $where);
    }

    protected function createBorderFromPoint(Point $point, int $radius)
    {
        return Zone::createPolygonFromPoint($point, $radius);
    }

    protected function pointFromLocation(mixed $location)
    {
        return Utils::getPointFromMixed($location);
    }

    protected function createZone(array $input): Zone
    {
        return Zone::create($input);
    }

    protected function findZoneRecord(string $id): Zone
    {
        return Zone::findRecordOrFail($id);
    }

    protected function queryZones(Request $request)
    {
        return Zone::queryWithRequest($request);
    }

    protected function zoneResource(Zone $zone)
    {
        return new ZoneResource($zone);
    }

    protected function zoneResourceCollection($results)
    {
        return ZoneResource::collection($results);
    }

    protected function deletedZoneResource(Zone $zone)
    {
        return new DeletedResource($zone);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}
