import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AdminRoutingSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked trackingProvider = 'google_routes';
    @tracked trackingFallbacks = ['osrm', 'calculated'];
    @tracked trackingTrafficEnabled = true;
    @tracked trackingCacheTtlSeconds = 60;
    @tracked trackingRouteCacheTtlSeconds = 600;
    @tracked trackingStaleLocationThresholdSeconds = 300;
    @tracked trackingDefaultVehicleSpeedKph = 35;
    @tracked trackingProviderOptions = [
        { value: 'google_routes', label: 'Google Routes' },
        { value: 'osrm', label: 'OSRM' },
        { value: 'calculated', label: 'Calculated' },
    ];

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-tracking-settings');
            this.trackingProvider = settings.provider ?? 'google_routes';
            this.trackingFallbacks = this.normalizeFallbacks(settings.fallbacks);
            this.trackingTrafficEnabled = settings.traffic_enabled ?? true;
            this.trackingCacheTtlSeconds = settings.cache_ttl_seconds ?? 60;
            this.trackingRouteCacheTtlSeconds = settings.route_cache_ttl_seconds ?? 600;
            this.trackingStaleLocationThresholdSeconds = settings.stale_location_threshold_seconds ?? 300;
            this.trackingDefaultVehicleSpeedKph = settings.default_vehicle_speed_kph ?? 35;
            this.trackingProviderOptions = this.normalizeProviderOptions(settings.providers ?? this.trackingProviderOptions);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/admin-tracking-settings', this.trackingSettingsPayload);
            this.notifications.success('Tracking defaults saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    get trackingSettingsPayload() {
        return {
            provider: this.trackingProvider,
            fallbacks: this.normalizeFallbacks(this.trackingFallbacks),
            traffic_enabled: this.trackingTrafficEnabled,
            cache_ttl_seconds: Number(this.trackingCacheTtlSeconds) || 60,
            route_cache_ttl_seconds: Number(this.trackingRouteCacheTtlSeconds) || 600,
            stale_location_threshold_seconds: Number(this.trackingStaleLocationThresholdSeconds) || 300,
            default_vehicle_speed_kph: Number(this.trackingDefaultVehicleSpeedKph) || 35,
        };
    }

    normalizeFallbacks(fallbacks) {
        if (Array.isArray(fallbacks)) {
            return fallbacks
                .map((fallback) => this.optionValue(fallback))
                .map((fallback) => String(fallback).trim())
                .filter(Boolean);
        }

        return String(fallbacks ?? '')
            .split(',')
            .map((fallback) => fallback.trim())
            .filter(Boolean);
    }

    get selectedTrackingFallbackOptions() {
        const selected = new Set(this.normalizeFallbacks(this.trackingFallbacks));

        return this.trackingProviderOptions.filter((option) => selected.has(this.optionValue(option)));
    }

    normalizeProviderOptions(options = []) {
        return options.map((option) => {
            const value = this.optionValue(option);
            const label = option?.label ?? option?.name ?? this.providerLabel(value);

            return {
                ...option,
                key: option?.key ?? value,
                name: option?.name ?? label,
                value,
                label,
            };
        });
    }

    providerLabel(value) {
        if (value === 'osrm') {
            return 'OSRM';
        }

        return String(value ?? '')
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, (character) => character.toUpperCase());
    }

    optionValue(option) {
        return typeof option === 'object' && option !== null ? (option.value ?? option.key) : option;
    }

    @action setTrackingFallbacks(options) {
        this.trackingFallbacks = this.normalizeFallbacks(options);
    }
}
