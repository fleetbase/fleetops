<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\FleetOps\Support\Routing\RoutingEngineResolver;
use Fleetbase\Models\Setting;
use Illuminate\Support\Arr;

class TrackingOptions
{
    public function __construct(
        public ?string $provider = null,
        public array $fallbacks = [],
        public bool $trafficEnabled = true,
        public int $cacheTtlSeconds = 60,
        public int $routeCacheTtlSeconds = 600,
        public int $staleLocationThresholdSeconds = 300,
        public float $defaultVehicleSpeedKph = 35,
        public array $raw = [],
    ) {
    }

    public static function fromArray(array $options = [], ?string $companyUuid = null): self
    {
        try {
            $systemSettings = Setting::lookup('fleet-ops.tracking-settings', []);
        } catch (\Throwable) {
            $systemSettings = [];
        }

        $companyUuid     = $companyUuid ?? session('company');
        $config          = array_merge(config('fleetops.tracking', []), is_array($systemSettings) ? $systemSettings : []);
        $companySettings = [];
        try {
            $companySettings = Setting::lookupForCompany($companyUuid, 'tracking', []);
        } catch (\Throwable) {
            $companySettings = [];
        }

        // An explicitly requested provider (API callers) is still subject to the routing policy
        $fallbacks = Arr::get($options, 'fallbacks', []);
        if (is_string($fallbacks)) {
            $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbacks))));
        }

        return new self(
            // Tracking uses the organization's routing engine, the same one that measured the order
            provider: Arr::get($options, 'provider') ?: RoutingEngineResolver::trackingProvider($companyUuid),
            fallbacks: Arr::wrap($fallbacks),
            trafficEnabled: (bool) Arr::get($options, 'traffic_enabled', Arr::get($companySettings, 'traffic_enabled', Arr::get($config, 'traffic_enabled', true))),
            cacheTtlSeconds: (int) Arr::get($options, 'cache_ttl_seconds', Arr::get($companySettings, 'cache_ttl_seconds', Arr::get($config, 'cache_ttl_seconds', 60))),
            routeCacheTtlSeconds: (int) Arr::get($options, 'route_cache_ttl_seconds', Arr::get($companySettings, 'route_cache_ttl_seconds', Arr::get($config, 'route_cache_ttl_seconds', 600))),
            staleLocationThresholdSeconds: (int) Arr::get($options, 'stale_location_threshold_seconds', Arr::get($companySettings, 'stale_location_threshold_seconds', Arr::get($config, 'stale_location_threshold_seconds', 300))),
            defaultVehicleSpeedKph: (float) Arr::get($options, 'default_vehicle_speed_kph', Arr::get($companySettings, 'default_vehicle_speed_kph', Arr::get($config, 'default_vehicle_speed_kph', 35))),
            raw: $options
        );
    }
}
