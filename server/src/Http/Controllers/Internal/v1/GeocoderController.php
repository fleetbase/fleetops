<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Support\Geocoding;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Illuminate\Http\Request;

class GeocoderController extends Controller
{
    /**
     * Reverse geocodes the given coordinates and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function reverse(Request $request)
    {
        $query  = $request->or(['coordinates', 'query']);
        $single = $request->boolean('single');

        // Resolve strictly: getPointFromCoordinates() is typed `: Point` and
        // falls back to Point(0, 0) for unusable input, which would silently
        // reverse-geocode Null Island instead of reporting the bad request
        /** @var \Fleetbase\LaravelMysqlSpatial\Types\Point|null $coordinates */
        $coordinates = Utils::getPointFromCoordinatesStrict($query);

        // if not a valid point error
        if (!$coordinates instanceof \Fleetbase\LaravelMysqlSpatial\Types\Point) {
            return response()->error('Invalid coordinates provided.');
        }

        // get results
        $results = $this->reverseGeocode($coordinates->getLat(), $coordinates->getLng());

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json($this->placeFromGoogleAddress($googleAddress));
            }

            return response()->json(
                $results->map(
                    function ($googleAddress) {
                        return $this->placeFromGoogleAddress($googleAddress);
                    }
                )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    /**
     * Geocodes the given query and returns the results as JSON.
     *
     * @param Request $request the HTTP request object
     *
     * @return \Illuminate\Http\Response the JSON response with the geocoded results
     */
    public function geocode(Request $request)
    {
        $query  = $request->input('query');
        $single = $request->boolean('single');

        if (is_array($query)) {
            return $this->reverse($request);
        }

        // lookup
        $results = $this->forwardGeocode($query);

        if ($results->count()) {
            if ($single) {
                $googleAddress = $results->first();

                return response()->json($this->placeFromGoogleAddress($googleAddress));
            }

            return response()->json(
                $results->map(
                    function ($googleAddress) {
                        return $this->placeFromGoogleAddress($googleAddress);
                    }
                )
                    ->values()
                    ->toArray()
            );
        }

        return response()->json([]);
    }

    // Both lookups go through the same geocoder places/lookup uses. The
    // geocoder-laravel facade built its provider from config/geocoder.php at
    // boot, before the settings-backed API key was loaded, and its chain
    // provider swallowed the resulting failure into an empty result.
    protected function reverseGeocode(float $latitude, float $longitude)
    {
        return collect(Geocoding::geocoder()->reverseQuery(ReverseQuery::fromCoordinates($latitude, $longitude))->all());
    }

    protected function forwardGeocode(string $query)
    {
        return collect(Geocoding::geocoder()->geocodeQuery(GeocodeQuery::create($query))->all());
    }

    protected function placeFromGoogleAddress($googleAddress)
    {
        return Place::createFromGoogleAddress($googleAddress);
    }
}
