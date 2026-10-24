<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;

/**
 * Decides which distance matrix provider measures an order's distance and time.
 *
 * Every candidate must be allowed for the organization by RoutingProviderPolicy
 * (Admin → Fleet-Ops → Routing), so switching a provider off there stops it at once.
 * In order of precedence:
 *   1. `DISTANCE_MATRIX_PROVIDER` when the operator set it, so an explicit
 *      platform choice (or `calculate` as a kill switch) wins.
 *   2. The organization's routing engine from Fleet-Ops Settings → Routing (the
 *      platform default display engine until it chooses one), when the server can
 *      call it: Valhalla or OSRM on a private server, or Google with a Maps key.
 *   3. The platform's own engine: the configured Valhalla server, then a
 *      private OSRM host.
 *   4. `calculate`, the straight-line estimate.
 */
class DistanceMatrixProviderResolver
{
    /**
     * The public OSRM demo server. Its usage policy allows at most one request
     * per second, so server-side order estimates are never sent there unless
     * the operator explicitly asks for OSRM.
     */
    public const PUBLIC_OSRM_HOST = 'router.project-osrm.org';

    /**
     * Self-hosted engines, in the order the platform prefers them.
     */
    public const PLATFORM_ENGINES = ['valhalla', 'osrm'];

    public static function resolve(?string $companyUuid = null): string
    {
        $configured = config('fleetops.distance_matrix.provider');
        if (filled($configured)) {
            return RoutingProviderPolicy::allows($configured, $companyUuid) ? (string) $configured : 'calculate';
        }

        $engine = static::fromRoutingSettings($companyUuid);
        if ($engine && static::isAvailable($engine, $companyUuid)) {
            return $engine;
        }

        foreach (static::PLATFORM_ENGINES as $platformEngine) {
            if (static::isAvailable($platformEngine, $companyUuid)) {
                return $platformEngine;
            }
        }

        return 'calculate';
    }

    /**
     * Maps the organization's routing display engine onto a server-side provider.
     * Engines the server cannot call (VROOM, custom engines) map to null.
     */
    public static function fromRoutingSettings(?string $companyUuid = null): ?string
    {
        try {
            $settings = Setting::lookupForCompany($companyUuid, 'routing', []);
        } catch (\Throwable) {
            $settings = [];
        }

        $engine = data_get($settings, 'display_engine', data_get($settings, 'routing_display_engine', data_get($settings, 'router', RoutingProviderPolicy::defaultDisplayEngine())));

        return match ($engine) {
            'osrm'     => 'osrm',
            'valhalla' => 'valhalla',
            'google'   => 'google',
            default    => null,
        };
    }

    public static function isAvailable(string $provider, ?string $companyUuid = null): bool
    {
        if (!RoutingProviderPolicy::allows($provider, $companyUuid)) {
            return false;
        }

        return match ($provider) {
            'osrm'     => static::hasPrivateOsrmHost(),
            'valhalla' => ValhallaClient::isConfigured($companyUuid),
            'google'   => filled(static::googleMapsApiKey()),
            default    => false,
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
