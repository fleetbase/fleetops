<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Brick\Geo\Point;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\ServiceRate;
use Illuminate\Http\Request;

class ServiceRateController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'service_rate';

    /**
     * Creates a record with request payload.
     *
     * @return \Illuminate\Http\Response
     */
    public function getServicesForRoute(Request $request)
    {
        $coordinates = explode(';', $request->input('coordinates')); // ex. 1.3621663,103.8845049;1.353151,103.86458

        // convert coordinates to points
        $waypoints = collect($coordinates)->map(
            function ($coord) {
                $coord                  = explode(',', $coord);
                [$latitude, $longitude] = $coord;

                return Point::fromText("POINT($longitude $latitude)", 4326);
            }
        );

        $applicableServiceRates = ServiceRate::getServicableForWaypoints(
            $waypoints,
            function ($query) use ($request) {
                $query->where('company_uuid', $request->session()->get('company'));
            }
        );

        return response()->json($applicableServiceRates);
    }
}
