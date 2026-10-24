<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal client for a Valhalla routing server's `/route` endpoint.
 *
 * The server is the one the Valhalla extension is configured with, resolved the
 * same way: the organization's own `valhalla` setting, then the system setting
 * from Admin → Fleet-Ops → Routing, then `fleetops.valhalla.*` (VALHALLA_HOST /
 * VALHALLA_BASE_URI / VALHALLA_API_KEY). The key is sent as the `X-Api-Key` header.
 */
class ValhallaClient
{
    /**
     * Public community servers. Their usage policies do not allow production
     * traffic, so they never count as a configured server.
     */
    public const PUBLIC_HOSTS = ['valhalla1.openstreetmap.de', 'valhalla2.openstreetmap.de'];

    public const CACHE_TTL = 30;

    /**
     * The first usable host with the key configured beside it, or null when
     * only a public server (or nothing) is configured.
     *
     * @return array{host: string, api_key: ?string}|null
     */
    public static function connection(?string $companyUuid = null): ?array
    {
        $settings = Cache::remember('fleetops:valhalla-connection:' . ($companyUuid ?? 'system'), static::CACHE_TTL, function () use ($companyUuid) {
            return [
                static::lookup(fn () => Setting::lookupForCompany($companyUuid, 'valhalla', [])),
                static::lookup(fn () => Setting::lookup('valhalla', [])),
            ];
        });

        foreach ([...$settings, ['api_host' => config('fleetops.valhalla.host'), 'api_key' => config('fleetops.valhalla.api_key')]] as $level) {
            $host = rtrim(trim((string) data_get($level, 'api_host')), '/');
            if (static::isPrivateHost($host)) {
                return ['host' => $host, 'api_key' => data_get($level, 'api_key') ?: null];
            }
        }

        return null;
    }

    public static function isConfigured(?string $companyUuid = null): bool
    {
        return static::connection($companyUuid) !== null;
    }

    public static function isPrivateHost(?string $host): bool
    {
        $hostname = parse_url((string) $host, PHP_URL_HOST);

        return filled($hostname) && !in_array(strtolower($hostname), static::PUBLIC_HOSTS, true);
    }

    /**
     * Routes through the given points in order and returns Valhalla's `trip`
     * (summary length in kilometres, time in seconds, and one entry per leg).
     *
     * @param Point[] $points
     *
     * @throws \RuntimeException when no server is configured, the server fails or it finds no route
     */
    public static function route(array $points, array $options = [], ?string $companyUuid = null): array
    {
        $connection = static::connection($companyUuid);
        if (!$connection) {
            throw new \RuntimeException('No Valhalla server is configured.');
        }

        $request = Http::timeout((int) config('fleetops.valhalla.timeout', 5))->acceptJson();
        if ($connection['api_key']) {
            $request = $request->withHeaders(['X-Api-Key' => $connection['api_key']]);
        }

        $response = $request->post($connection['host'] . '/route', array_merge([
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

    protected static function lookup(callable $lookup): array
    {
        try {
            $settings = $lookup();
        } catch (\Throwable) {
            $settings = [];
        }

        return is_array($settings) ? $settings : [];
    }
}
