<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;

/**
 * Decides which routing engine answers "how far, and how long, by road?" for an
 * organization. One answer serves the order's whole life: its stored distance and
 * time at creation and on edits, and the live ETAs, remaining distance and route
 * line while it is in progress.
 *
 * Every candidate must be allowed for the organization by RoutingProviderPolicy
 * (Admin → Fleet-Ops → Routing). In order:
 *   1. Straight-line when the administrator switched server-side routing off.
 *   2. The organization's routing engine (Fleet-Ops Settings → Routing).
 *   3. The platform default routing engine.
 *   4. The platform's own Valhalla server.
 *   5. `calculate`, the straight-line estimate.
 * An engine counts only when the server can call it: Valhalla or OSRM on a
 * private server, or Google with a Maps key. OSRM is used only when an
 * organization chose it and a private OSRM host is configured; it is never a
 * fallback, and the public OSRM demo server is never called.
 */
class RoutingEngineResolver
{
    /**
     * The public OSRM demo server. Its usage policy allows at most one request
     * per second, so server-side routing never sends traffic there.
     */
    public const PUBLIC_OSRM_HOST = 'router.project-osrm.org';

    /**
     * The platform's own engine, tried when the organization's engine is unavailable.
     */
    public const PLATFORM_ENGINES = ['valhalla'];

    /**
     * Tracking provider keys for routing engines whose tracking provider is named differently.
     */
    public const TRACKING_PROVIDERS = ['google' => 'google_routes', 'calculate' => 'calculated'];

    public static function resolve(?string $companyUuid = null): string
    {
        if (RoutingProviderPolicy::usesStraightLine()) {
            return 'calculate';
        }

        foreach ([static::companyEngine($companyUuid), RoutingProviderPolicy::defaultDisplayEngine(), ...static::PLATFORM_ENGINES] as $engine) {
            if ($engine && static::isAvailable($engine, $companyUuid)) {
                return $engine;
            }
        }

        return 'calculate';
    }

    /**
     * The tracking provider that serves the organization's routing engine.
     */
    public static function trackingProvider(?string $companyUuid = null): string
    {
        $engine = static::resolve($companyUuid);

        return static::TRACKING_PROVIDERS[$engine] ?? $engine;
    }

    /**
     * The routing engine the organization chose, or null when it never chose one.
     */
    public static function companyEngine(?string $companyUuid = null): ?string
    {
        try {
            $settings = Setting::lookupForCompany($companyUuid, 'routing', []);
        } catch (\Throwable) {
            $settings = [];
        }

        return data_get($settings, 'display_engine', data_get($settings, 'routing_display_engine', data_get($settings, 'router')));
    }

    public static function isAvailable(string $engine, ?string $companyUuid = null): bool
    {
        if (!RoutingProviderPolicy::allows($engine, $companyUuid)) {
            return false;
        }

        return match ($engine) {
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
