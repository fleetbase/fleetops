import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class SettingsRoutingController extends Controller {
    @service fetch;
    @service notifications;
    @service currentUser;
    @service routeEngine;
    @service routeOptimization;
    @tracked displayEngine = 'osrm';
    @tracked optimizationEngine = 'osrm';
    @tracked routingUnit = 'km';
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
    @tracked routingUnitOptions = [
        { label: 'Kilometers', value: 'km' },
        { label: 'Miles', value: 'mi' },
    ];
    saveTasks = new Map();

    get hasEngineOverrides() {
        return ['vroom', 'valhalla'].includes(this.displayEngine) || ['vroom', 'valhalla'].includes(this.optimizationEngine);
    }

    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    /**
     * Save routing settings.
     *
     * @memberof SettingsRoutingController
     */
    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/routing-settings', {
                display_engine: this.displayEngine,
                optimization_engine: this.optimizationEngine,
                unit: this.routingUnit,
            });
            const trackingSettings = this.trackingSettingsPayload;
            yield this.fetch.post('fleet-ops/settings/tracking-settings', trackingSettings);
            yield this.performAdditionalSaveTasks();
            // Save in local memory too
            this.currentUser.setOption('routing', {
                router: this.displayEngine,
                routing_display_engine: this.displayEngine,
                routing_optimization_engine: this.optimizationEngine,
                unit: this.routingUnit,
            });
            this.currentUser.setOption('tracking', trackingSettings);
            this.notifications.success('Routing and tracking settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Get routing settings.
     *
     * @memberof SettingsRoutingController
     */
    @task *getSettings() {
        try {
            const { router, display_engine, optimization_engine, unit } = yield this.fetch.get('fleet-ops/settings/routing-settings');
            this.displayEngine = display_engine ?? router ?? 'osrm';
            this.optimizationEngine = optimization_engine ?? display_engine ?? router ?? 'osrm';
            this.routingUnit = unit;
            const trackingSettings = yield this.fetch.get('fleet-ops/settings/tracking-settings');
            this.trackingProvider = trackingSettings.provider ?? 'google_routes';
            this.trackingFallbacks = this.normalizeFallbacks(trackingSettings.fallbacks);
            this.trackingTrafficEnabled = trackingSettings.traffic_enabled ?? true;
            this.trackingCacheTtlSeconds = trackingSettings.cache_ttl_seconds ?? 60;
            this.trackingRouteCacheTtlSeconds = trackingSettings.route_cache_ttl_seconds ?? 600;
            this.trackingStaleLocationThresholdSeconds = trackingSettings.stale_location_threshold_seconds ?? 300;
            this.trackingDefaultVehicleSpeedKph = trackingSettings.default_vehicle_speed_kph ?? 35;
            this.trackingProviderOptions = this.normalizeProviderOptions(trackingSettings.providers ?? this.trackingProviderOptions);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    get selectedTrackingFallbackOptions() {
        const selected = new Set(this.normalizeFallbacks(this.trackingFallbacks));

        return this.trackingProviderOptions.filter((option) => selected.has(this.optionValue(option)));
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

    registerSaveTask(key, task) {
        if (!key || typeof task?.perform !== 'function') {
            return;
        }

        this.saveTasks.set(key, task);
    }

    unregisterSaveTask(key) {
        if (!key) {
            return;
        }

        this.saveTasks.delete(key);
    }

    async performAdditionalSaveTasks() {
        for (const task of this.saveTasks.values()) {
            try {
                await task.perform();
            } catch (error) {
                // Ignore explicit ember-concurrency task cancellations from components
                // that may have unmounted during routing-engine selection changes.
                if (typeof error?.name === 'string' && error.name.includes('TaskCancelation')) {
                    continue;
                }

                throw error;
            }
        }
        return true;
    }
}
