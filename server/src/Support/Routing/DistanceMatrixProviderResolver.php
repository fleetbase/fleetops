<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;

/**
 * Decides which distance matrix provider measures an order's distance and time.
 *
 * In order of precedence:
 *   1. `DISTANCE_MATRIX_PROVIDER` when the operator set it, so an explicit
 *      platform choice (or `calculate` as a kill switch) always wins. This is
 *      the only way Google is ever used: metered providers are never picked
 *      from a company's settings.
 *   2. The company's routing engine from Fleet-Ops Settings → Routing, when it
 *      is a self-hosted engine the server is configured for (Valhalla, or OSRM
 *      on a host other than the public demo server).
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
            return (string) $configured;
        }

        $engine = static::fromRoutingSettings($companyUuid);
        if ($engine && static::isAvailable($engine)) {
            return $engine;
        }

        foreach (static::PLATFORM_ENGINES as $platformEngine) {
            if (static::isAvailable($platformEngine)) {
                return $platformEngine;
            }
        }

        return 'calculate';
    }

    /**
     * Maps the company's routing display engine onto a self-hosted provider.
     * Google and engines the server cannot call (VROOM, custom engines) map to null.
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
            'osrm'     => 'osrm',
            'valhalla' => 'valhalla',
            default    => null,
        };
    }

    public static function isAvailable(string $provider): bool
    {
        return match ($provider) {
            'osrm'     => static::hasPrivateOsrmHost(),
            'valhalla' => ValhallaClient::isConfigured(),
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
