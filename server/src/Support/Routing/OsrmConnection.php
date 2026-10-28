<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Which OSRM server Fleet-Ops calls.
 *
 * Resolved like the Valhalla server: the organization's own `osrm` setting
 * (Fleet-Ops Settings → Routing), then the system setting (Admin → Fleet-Ops →
 * Routing), then OSRM_HOST. An organization host must pass the HostPolicy
 * (public addresses only, unless OSRM_ALLOW_PRIVATE_HOSTS is set), or it is
 * ignored. The public OSRM demo server is the last resort for map display only:
 * its usage policy allows one request a second, so server-side routing never
 * counts it as configured.
 */
class OsrmConnection
{
    public const SETTING_KEY = 'osrm';

    public const PUBLIC_HOST = 'router.project-osrm.org';

    public const CACHE_TTL = 30;

    /**
     * The OSRM server for an organization and where it came from:
     * `organization`, `system`, `environment` or `default` (the public demo server).
     *
     * @return array{host: string, source: string}
     */
    public static function resolve(?string $companyUuid = null): array
    {
        $load = fn () => [
            'organization' => $companyUuid ? static::organizationHost(static::lookup(fn () => Setting::lookupForCompany($companyUuid, static::SETTING_KEY, []))) : null,
            'system'       => data_get(static::lookup(fn () => Setting::lookup(static::SETTING_KEY, [])), 'api_host'),
        ];
        $levels = Cache::remember(static::cacheKey($companyUuid), static::CACHE_TTL, $load);
        if (!is_array($levels)) {
            $levels = $load();
        }

        foreach ([...$levels, 'environment' => config('fleetops.osrm.host')] as $source => $host) {
            $host = rtrim(trim((string) $host), '/');
            if ($host !== '') {
                return ['host' => $host, 'source' => $source];
            }
        }

        return ['host' => 'https://' . static::PUBLIC_HOST, 'source' => 'default'];
    }

    public static function host(?string $companyUuid = null): string
    {
        return static::resolve($companyUuid)['host'];
    }

    /**
     * Whether the server may send traffic to the organization's OSRM server:
     * anything but the public demo server.
     */
    public static function isConfigured(?string $companyUuid = null): bool
    {
        return static::isPrivateHost(static::host($companyUuid));
    }

    public static function isPrivateHost(?string $host): bool
    {
        $hostname = parse_url((string) $host, PHP_URL_HOST);

        return filled($hostname) && strcasecmp($hostname, static::PUBLIC_HOST) !== 0;
    }

    /**
     * Drop the cached resolution after a settings change.
     */
    public static function forget(?string $companyUuid = null): void
    {
        Cache::forget(static::cacheKey($companyUuid));
    }

    public static function allowsPrivateHosts(): bool
    {
        return filter_var(config('fleetops.osrm.allow_private_hosts', false), FILTER_VALIDATE_BOOLEAN);
    }

    protected static function cacheKey(?string $companyUuid): string
    {
        return 'fleetops:osrm-connection:' . ($companyUuid ?? 'system');
    }

    /**
     * An organization host the server must not call (a private, loopback or
     * metadata address) is ignored, so the system server is used instead.
     */
    protected static function organizationHost(array $settings): ?string
    {
        $host = trim((string) data_get($settings, 'api_host'));
        if ($host === '' || !HostPolicy::allows($host, static::allowsPrivateHosts())) {
            return null;
        }

        return $host;
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
