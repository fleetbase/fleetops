<?php

namespace Fleetbase\FleetOps\Tracking;

use Fleetbase\Models\Setting;
use Illuminate\Support\Arr;

class TrackingOptions
{
    public function __construct(
        public ?string $provider = null,
        public array $fallbacks = [],
        public bool $trafficEnabled = true,
        public int $cacheTtlSeconds = 60,
        public int $staleLocationThresholdSeconds = 300,
        public float $defaultVehicleSpeedKph = 35,
        public array $raw = [],
    ) {
    }

    public static function fromArray(array $options = []): self
    {
        $config          = config('fleetops.tracking', []);
        $companySettings = [];
        try {
            $companySettings = Setting::lookupCompany('tracking', []);
        } catch (\Throwable) {
            $companySettings = [];
        }

        $fallbacks = Arr::get($options, 'fallbacks', Arr::get($companySettings, 'fallbacks', Arr::get($config, 'fallbacks', [])));
        if (is_string($fallbacks)) {
            $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbacks))));
        }

        return new self(
            provider: Arr::get($options, 'provider', Arr::get($companySettings, 'provider', Arr::get($config, 'provider'))),
            fallbacks: Arr::wrap($fallbacks),
            trafficEnabled: (bool) Arr::get($options, 'traffic_enabled', Arr::get($companySettings, 'traffic_enabled', Arr::get($config, 'traffic_enabled', true))),
            cacheTtlSeconds: (int) Arr::get($options, 'cache_ttl_seconds', Arr::get($companySettings, 'cache_ttl_seconds', Arr::get($config, 'cache_ttl_seconds', 60))),
            staleLocationThresholdSeconds: (int) Arr::get($options, 'stale_location_threshold_seconds', Arr::get($companySettings, 'stale_location_threshold_seconds', Arr::get($config, 'stale_location_threshold_seconds', 300))),
            defaultVehicleSpeedKph: (float) Arr::get($options, 'default_vehicle_speed_kph', Arr::get($companySettings, 'default_vehicle_speed_kph', Arr::get($config, 'default_vehicle_speed_kph', 35))),
            raw: $options
        );
    }
}
