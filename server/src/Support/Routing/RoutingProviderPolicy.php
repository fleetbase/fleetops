<?php

namespace Fleetbase\FleetOps\Support\Routing;

use Fleetbase\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's rules for which routing providers organizations may use.
 *
 * Managed from Admin → Fleet-Ops → Routing and stored as one system setting:
 *   - `providers`: whether each provider is available to every organization.
 *     Google is billed per request and is off unless switched on.
 *   - `defaults`: the route display and optimization engines organizations get
 *     until they choose their own.
 *   - `exceptions`: providers switched on for named organizations only.
 *
 * It is enforced on the server for order distance, tracking intelligence and
 * organization settings, and handed to the console so disabled engines are
 * neither offered nor used for map routing. The straight-line `calculate` and
 * `calculated` providers are always allowed so every caller has a fallback.
 */
class RoutingProviderPolicy
{
    public const SETTING_KEY = 'fleet-ops.routing-providers';

    public const CACHE_KEY = 'fleetops:routing-providers';

    /**
     * Seconds a saved policy may take to reach every worker. Saving from the
     * admin panel clears it immediately on the worker that saved.
     */
    public const CACHE_TTL = 30;

    public const PROVIDERS = [
        'google'   => 'Google',
        'valhalla' => 'Valhalla',
        'osrm'     => 'OSRM',
        'vroom'    => 'VROOM',
    ];

    /**
     * Provider keys used by individual features that belong to one provider.
     */
    public const ALIASES = ['google_routes' => 'google'];

    public const ALWAYS_ALLOWED = ['calculate', 'calculated'];

    public static function settings(): array
    {
        $settings = Cache::remember(static::CACHE_KEY, static::CACHE_TTL, fn () => static::normalize(static::stored()));

        // A cache store that cannot hold the policy must not take routing down with it
        return is_array($settings) ? $settings : static::normalize(static::stored());
    }

    public static function save(array $input): array
    {
        $settings = static::normalize($input);
        Setting::configure(static::SETTING_KEY, $settings);
        Cache::forget(static::CACHE_KEY);

        return $settings;
    }

    public static function normalize(array $settings): array
    {
        $providers = [];
        foreach (array_keys(static::PROVIDERS) as $provider) {
            $default               = $provider === 'google' ? config('fleetops.routing.google_enabled', false) : true;
            $providers[$provider]  = filter_var(data_get($settings, 'providers.' . $provider, $default), FILTER_VALIDATE_BOOLEAN);
        }

        $exceptions = collect(data_get($settings, 'exceptions', []))
            ->map(fn ($exception) => [
                'company_uuid' => (string) data_get($exception, 'company_uuid', ''),
                'company_name' => data_get($exception, 'company_name'),
                'providers'    => array_values(array_unique(array_intersect(
                    array_map(fn ($provider) => static::provider($provider), (array) data_get($exception, 'providers', [])),
                    array_keys(static::PROVIDERS)
                ))),
            ])
            ->filter(fn ($exception) => $exception['company_uuid'] !== '' && $exception['providers'] !== [])
            ->unique('company_uuid')
            ->values()
            ->all();

        return [
            'providers' => $providers,
            'defaults'  => [
                'display_engine'      => (string) data_get($settings, 'defaults.display_engine', config('fleetops.routing.display_engine', 'osrm')),
                'optimization_engine' => (string) data_get($settings, 'defaults.optimization_engine', config('fleetops.routing.optimization_engine', 'osrm')),
            ],
            'exceptions' => $exceptions,
        ];
    }

    /**
     * The provider a feature-specific key belongs to, or null for the
     * always-allowed straight-line providers.
     */
    public static function provider(?string $key): ?string
    {
        $key = strtolower(trim((string) $key));
        if ($key === '' || in_array($key, static::ALWAYS_ALLOWED, true)) {
            return null;
        }

        return static::ALIASES[$key] ?? $key;
    }

    public static function allows(?string $key, ?string $companyUuid = null): bool
    {
        $provider = static::provider($key);
        if ($provider === null) {
            return true;
        }

        $settings = static::settings();

        // Providers registered by other extensions are governed by those extensions
        if (!array_key_exists($provider, $settings['providers']) || $settings['providers'][$provider]) {
            return true;
        }

        return $companyUuid !== null && collect($settings['exceptions'])->contains(
            fn ($exception) => $exception['company_uuid'] === $companyUuid && in_array($provider, $exception['providers'], true)
        );
    }

    /**
     * @return string[] the built-in providers the organization may use
     */
    public static function allowedProviders(?string $companyUuid = null): array
    {
        return array_values(array_filter(array_keys(static::PROVIDERS), fn ($provider) => static::allows($provider, $companyUuid)));
    }

    public static function defaultDisplayEngine(): string
    {
        return static::settings()['defaults']['display_engine'];
    }

    public static function defaultOptimizationEngine(): string
    {
        return static::settings()['defaults']['optimization_engine'];
    }

    /**
     * The engine the organization may use: its own choice when allowed, otherwise
     * the platform default, otherwise OSRM.
     */
    public static function engineFor(?string $engine, string $default, ?string $companyUuid = null): string
    {
        foreach ([$engine, $default] as $candidate) {
            if (filled($candidate) && static::allows($candidate, $companyUuid)) {
                return $candidate;
            }
        }

        return 'osrm';
    }

    protected static function stored(): array
    {
        try {
            $settings = Setting::lookup(static::SETTING_KEY, []);
        } catch (\Throwable) {
            $settings = [];
        }

        return is_array($settings) ? $settings : [];
    }
}
