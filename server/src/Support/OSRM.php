<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Support\Encoding\Polyline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Grimzy\LaravelMysqlSpatial\Types\Point;

/**
 * Class OSRM
 * A support class to interact with the Open Source Routing Machine (OSRM) API.
 */
class OSRM
{
    protected static $baseUrl = 'https://bundle.routing.fleetbase.io';

    /**
     * Get the route between two points.
     *
     * @param Point $start Starting point.
     * @param Point $end Ending point.
     * @param array $queryParameters Additional query parameters.
     * @return array Response from the OSRM API.
     */
    public static function getRoute(Point $start, Point $end, array $queryParameters = [])
    {
        // Generate a unique cache key based on the method parameters
        $cacheKey = 'getRoute:' . md5($start . $end . serialize($queryParameters));

        // Return the cached result if it exists
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = "{$start->getLng()},{$start->getLat()};{$end->getLng()},{$end->getLat()}";
        $url = self::$baseUrl . "/route/v1/driving/{$coordinates}";
        $response = Http::get($url, $queryParameters);
        $data = $response->json();

        // Check for the presence of the encoded polyline in each route and decode it if found
        if (isset($data['routes']) && is_array($data['routes'])) {
            foreach ($data['routes'] as &$route) {
                if (isset($route['geometry'])) {
                    $route['waypoints'] = self::decodePolyline($route['geometry']);
                }
            }
        }

        // Store the result in the cache for 60 minutes
        Cache::put($cacheKey, $data, 60 * 60);

        return $data;
    }

    /**
     * Get the nearest point on a road to a given location.
     *
     * @param Point $location Location point.
     * @param array $queryParameters Additional query parameters.
     * @return array Response from the OSRM API.
     */
    public static function getNearest(Point $location, array $queryParameters = [])
    {
        $cacheKey = 'getNearest:' . md5($location . serialize($queryParameters));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = "{$location->getLng()},{$location->getLat()}";
        $url = self::$baseUrl . "/nearest/v1/driving/{$coordinates}";
        $response = Http::get($url, $queryParameters);
        $result = $response->json();

        Cache::put($cacheKey, $result, 60 * 60);

        return $result;
    }


    /**
     * Get a table of travel times or distances between multiple points.
     *
     * @param array $points Array of Point objects.
     * @param array $queryParameters Additional query parameters.
     * @return array Response from the OSRM API.
     */
    public static function getTable(array $points, array $queryParameters = [])
    {
        $cacheKey = 'getTable:' . md5(serialize($points) . serialize($queryParameters));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = implode(';', array_map(function (Point $point) {
            return "{$point->getLng()},{$point->getLat()}";
        }, $points));

        $url = self::$baseUrl . "/table/v1/driving/{$coordinates}";
        $response = Http::get($url, $queryParameters);
        $result = $response->json();

        Cache::put($cacheKey, $result, 60 * 60);

        return $result;
    }

    /**
     * Get a trip between multiple points.
     *
     * @param array $points Array of Point objects.
     * @param array $queryParameters Additional query parameters.
     * @return array Response from the OSRM API.
     */
    public static function getTrip(array $points, array $queryParameters = [])
    {
        $cacheKey = 'getTrip:' . md5(serialize($points) . serialize($queryParameters));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $coordinates = implode(';', array_map(function (Point $point) {
            return "{$point->getLng()},{$point->getLat()}";
        }, $points));

        $url = self::$baseUrl . "/trip/v1/driving/{$coordinates}";
        $response = Http::get($url, $queryParameters);
        $data = $response->json();

        Cache::put($cacheKey, $data, 60 * 60);

        return $data;
    }

    /**
     * Get a match between GPS points and roads.
     *
     * @param array $points Array of Point objects.
     * @param array $queryParameters Additional query parameters.
     * @return array Response from the OSRM API.
     */
    public static function getMatch(array $points, array $queryParameters = [])
    {
        $coordinates = implode(';', array_map(function (Point $point) {
            return "{$point->getLng()},{$point->getLat()}";
        }, $points));
        $url = self::$baseUrl . "/match/v1/driving/{$coordinates}";

        $response = Http::get($url, $queryParameters);

        return $response->json();
    }

    /**
     * Get a tile for a specific zoom level and coordinates.
     *
     * @param int $z Zoom level.
     * @param int $x X coordinate.
     * @param int $y Y coordinate.
     * @param array $queryParameters Additional query parameters.
     * @return string Response from the OSRM API.
     */
    public static function getTile(int $z, int $x, int $y, array $queryParameters = [])
    {
        $url = self::$baseUrl . "/tile/v1/car/{$z}/{$x}/{$y}.mvt";

        $response = Http::get($url, $queryParameters);

        return $response->body();
    }

    /**
     * Decodes an encoded polyline string into an array of coordinates.
     *
     * @param string $polyline The encoded polyline string.
     * @return array An array of Point's.
     */
    public static function decodePolyline($polyline)
    {
        return Polyline::decode($polyline);
    }
}
