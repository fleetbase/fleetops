<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for a Valhalla routing server's `/route` endpoint.
 *
 * Configured by `fleetops.valhalla.host` and `fleetops.valhalla.api_key`, read
 * from the same VALHALLA_HOST / VALHALLA_BASE_URI / VALHALLA_API_KEY variables
 * the Valhalla extension uses. The key is sent as the `X-Api-Key` header.
 */
class ValhallaClient
{
    /**
     * Public community servers. Their usage policies do not allow production
     * traffic, so they never count as a configured server.
     */
    public const PUBLIC_HOSTS = ['valhalla1.openstreetmap.de', 'valhalla2.openstreetmap.de'];

    public static function host(): ?string
    {
        $host = rtrim((string) config('fleetops.valhalla.host'), '/');

        return $host !== '' ? $host : null;
    }

    public static function apiKey(): ?string
    {
        return config('fleetops.valhalla.api_key') ?: null;
    }

    public static function isConfigured(): bool
    {
        $host = parse_url((string) static::host(), PHP_URL_HOST);

        return filled($host) && !in_array(strtolower($host), static::PUBLIC_HOSTS, true);
    }

    /**
     * Routes through the given points in order and returns Valhalla's `trip`
     * (summary length in kilometres, time in seconds, and one entry per leg).
     *
     * @param Point[] $points
     *
     * @throws \RuntimeException when the server fails or finds no route
     */
    public static function route(array $points, array $options = []): array
    {
        $request = Http::timeout((int) config('fleetops.valhalla.timeout', 5))->acceptJson();
        if ($apiKey = static::apiKey()) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        $response = $request->post(static::host() . '/route', array_merge([
            'locations'       => array_map(fn (Point $point) => ['lat' => $point->getLat(), 'lon' => $point->getLng()], array_values($points)),
            'costing'         => config('fleetops.valhalla.costing', 'auto'),
            'units'           => 'kilometers',
            'directions_type' => 'none',
            'shape_format'    => 'polyline6',
        ], $options));

        if (!$response->successful()) {
            throw new \RuntimeException('Valhalla request failed with status ' . $response->status() . ': ' . data_get($response->json(), 'error', 'unknown error'));
        }

        $trip = data_get($response->json(), 'trip');
        if (!is_array($trip) || (int) data_get($trip, 'status', -1) !== 0) {
            throw new \RuntimeException('Valhalla returned no route.');
        }

        return $trip;
    }
}
