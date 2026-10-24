import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class AdminRoutingSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service routeEngine;
    @service routeOptimization;
    @tracked providerAccess = { google: false, valhalla: true, osrm: true, vroom: true };
    @tracked availableProviders = [
        { key: 'google', label: 'Google' },
        { key: 'valhalla', label: 'Valhalla' },
        { key: 'osrm', label: 'OSRM' },
        { key: 'vroom', label: 'VROOM' },
    ];
    @tracked defaultDisplayEngine = 'osrm';
    @tracked defaultOptimizationEngine = 'osrm';
    @tracked providerExceptions = [];
    @tracked trackingProvider = 'valhalla';
    @tracked trackingFallbacks = ['osrm', 'calculated'];
    @tracked trackingTrafficEnabled = true;
    @tracked trackingCacheTtlSeconds = 60;
    @tracked trackingRouteCacheTtlSeconds = 600;
    @tracked trackingStaleLocationThresholdSeconds = 300;
    @tracked trackingDefaultVehicleSpeedKph = 35;
    @tracked showTrackingAdvancedSettings = false;
    @tracked trackingProviderOptions = [
        { value: 'valhalla', label: 'Valhalla' },
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
            const policy = yield this.fetch.get('fleet-ops/settings/admin-routing-providers');
            this.applyProviderPolicy(policy);

            const settings = yield this.fetch.get('fleet-ops/settings/admin-tracking-settings');
            this.trackingProvider = settings.provider ?? 'valhalla';
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
            const policy = yield this.fetch.post('fleet-ops/settings/admin-routing-providers', this.providerPolicyPayload);
            this.applyProviderPolicy(policy);
            yield this.fetch.post('fleet-ops/settings/admin-tracking-settings', this.trackingSettingsPayload);
            this.notifications.success('Routing settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    applyProviderPolicy(policy = {}) {
        this.providerAccess = { ...this.providerAccess, ...(policy.providers ?? {}) };
        this.availableProviders = policy.available_providers ?? this.availableProviders;
        this.defaultDisplayEngine = policy.defaults?.display_engine ?? this.defaultDisplayEngine;
        this.defaultOptimizationEngine = policy.defaults?.optimization_engine ?? this.defaultOptimizationEngine;
        this.providerExceptions = (policy.exceptions ?? []).map((exception) => ({
            company_uuid: exception.company_uuid,
            company_name: exception.company_name ?? exception.company_uuid,
            providers: [...(exception.providers ?? [])],
        }));
    }

    get providerPolicyPayload() {
        return {
            providers: { ...this.providerAccess },
            defaults: {
                display_engine: this.defaultDisplayEngine,
                optimization_engine: this.defaultOptimizationEngine,
            },
            exceptions: this.providerExceptions
                .filter((exception) => exception.company_uuid && exception.providers.length > 0)
                .map(({ company_uuid, company_name, providers }) => ({ company_uuid, company_name, providers })),
        };
    }

    get providerAccessRows() {
        return this.availableProviders.map((provider) => ({
            ...provider,
            enabled: this.providerAccess[provider.key] === true,
            billed: provider.key === 'google',
        }));
    }

    /**
     * Providers that are switched off and can therefore be granted to single organizations.
     */
    get exceptionProviderOptions() {
        return this.availableProviders.filter((provider) => this.providerAccess[provider.key] !== true);
    }

    get displayEngineOptions() {
        return this.routeEngine.allServices;
    }

    get optimizationEngineOptions() {
        return this.routeOptimization.allServices;
    }

    get providerExceptionRows() {
        return this.providerExceptions.map((exception) => ({
            exception,
            selected: this.availableProviders.filter((provider) => exception.providers.includes(provider.key)),
        }));
    }

    @action toggleProvider(key, enabled) {
        this.providerAccess = { ...this.providerAccess, [key]: enabled };
    }

    @action addProviderException(company) {
        const companyUuid = company?.uuid ?? company?.id;
        if (!companyUuid || this.providerExceptions.some((exception) => exception.company_uuid === companyUuid)) {
            return;
        }

        this.providerExceptions = [...this.providerExceptions, { company_uuid: companyUuid, company_name: company.name, providers: [] }];
    }

    @action setExceptionProviders(exception, providers) {
        const keys = providers.map((provider) => provider.key);
        this.providerExceptions = this.providerExceptions.map((item) => (item === exception ? { ...item, providers: keys } : item));
    }

    @action removeProviderException(exception) {
        this.providerExceptions = this.providerExceptions.filter((item) => item !== exception);
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

    @action toggleTrackingAdvancedSettings() {
        this.showTrackingAdvancedSettings = !this.showTrackingAdvancedSettings;
    }
}
