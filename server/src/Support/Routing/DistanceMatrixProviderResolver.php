<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;

/**
 * Decides which distance matrix provider measures an order's distance and time.
 *
 * In order of precedence:
 *   1. `DISTANCE_MATRIX_PROVIDER` when the operator set it, so an explicit
 *      platform choice (or `calculate` as a kill switch) always wins.
 *   2. The company's routing engine from Fleet-Ops Settings → Routing, the same
 *      engine that draws the route on the map (OSRM unless changed), when the
 *      server can serve it: OSRM needs a self-hosted `OSRM_HOST` rather than
 *      the public demo server, Google needs a Maps API key.
 *   3. `calculate`, the straight-line estimate.
 */
class DistanceMatrixProviderResolver
{
    /**
     * The public OSRM demo server. Its usage policy allows at most one request
     * per second, so server-side order estimates are never sent there unless
     * the operator explicitly asks for OSRM.
     */
    public const PUBLIC_OSRM_HOST = 'router.project-osrm.org';

    public static function resolve(?string $companyUuid = null): string
    {
        $configured = config('fleetops.distance_matrix.provider');
        if (filled($configured)) {
            return (string) $configured;
        }

        $provider = static::fromRoutingSettings($companyUuid);
        if ($provider && static::isAvailable($provider)) {
            return $provider;
        }

        return 'calculate';
    }

    /**
     * Maps the company's routing display engine onto a server-side provider.
     * Engines the server cannot call (Valhalla, VROOM, custom engines) map to null.
     */
    public static function fromRoutingSettings(?string $companyUuid = null): ?string
    {
        try {
            $settings = Setting::lookupForCompany($companyUuid, 'routing', []);
        } catch (\Throwable) {
            $settings = [];
        }

        $engine = data_get($settings, 'display_engine', data_get($settings, 'routing_display_engine', data_get($settings, 'router', 'osrm')));

        return match ($engine) {
            'osrm'   => 'osrm',
            'google' => 'google',
            default  => null,
        };
    }

    public static function isAvailable(string $provider): bool
    {
        return match ($provider) {
            'osrm'   => static::hasPrivateOsrmHost(),
            'google' => filled(static::googleMapsApiKey()),
            default  => false,
        };
    }

    public static function hasPrivateOsrmHost(): bool
    {
        $host = parse_url((string) config('fleetops.osrm.host'), PHP_URL_HOST);

        return filled($host) && strcasecmp($host, static::PUBLIC_OSRM_HOST) !== 0;
    }

    public static function googleMapsApiKey(): ?string
    {
        $configuredKey = config('services.google_maps.api_key');
        if ($configuredKey) {
            return $configuredKey;
        }

        return class_exists(\PhpOption\Option::class) ? env('GOOGLE_MAPS_API_KEY') : null;
    }
}
