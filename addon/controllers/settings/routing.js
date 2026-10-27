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
    @tracked displayEngine = 'valhalla';
    @tracked optimizationEngine = 'valhalla';
    @tracked routingUnit = 'km';
    @tracked trackingTrafficEnabled = true;
    @tracked trackingCacheTtlSeconds = 60;
    @tracked trackingRouteCacheTtlSeconds = 600;
    @tracked trackingStaleLocationThresholdSeconds = 300;
    @tracked trackingDefaultVehicleSpeedKph = 35;
    @tracked lateDepartureAlertsEnabled = true;
    @tracked lateDepartureGracePeriodMinutes = 15;
    @tracked routeDeviationAlertsEnabled = true;
    @tracked routeDeviationDistanceThresholdMeters = 500;
    @tracked prolongedStoppageAlertsEnabled = true;
    @tracked prolongedStoppageDurationThresholdMinutes = 30;
    @tracked showTrackingAdvancedSettings = false;
    @tracked osrmUseOwnServer = false;
    @tracked osrmHost = '';
    @tracked osrmSystemHost = null;
    @tracked routingUnitOptions = [
        { label: 'Kilometers', value: 'km' },
        { label: 'Miles', value: 'mi' },
    ];
    saveTasks = new Map();

    /** Whether the organization routes or optimizes with OSRM, so its own OSRM server applies. */
    get usesOsrm() {
        return this.displayEngine === 'osrm' || this.optimizationEngine === 'osrm';
    }

    get osrmSystemHelpText() {
        return this.osrmSystemHost ? `Otherwise this organization uses ${this.osrmSystemHost}.` : 'Otherwise this organization uses the platform OSRM server.';
    }

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
            if (this.usesOsrm) {
                yield this.fetch.post('fleet-ops/settings/osrm-settings', { api_host: this.osrmUseOwnServer ? this.osrmHost : null });
            }
            yield this.performAdditionalSaveTasks();
            // Refresh the routing settings in memory: they carry the resolved OSRM server the map uses
            const routingSettings = yield this.fetch.get('fleet-ops/settings/routing-settings');
            this.currentUser.setOption('routing', routingSettings);
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
            this.displayEngine = display_engine ?? router ?? 'valhalla';
            this.optimizationEngine = optimization_engine ?? display_engine ?? router ?? 'valhalla';
            this.routingUnit = unit;
            yield this.loadOsrmSettings.perform();
            const trackingSettings = yield this.fetch.get('fleet-ops/settings/tracking-settings');
            this.trackingTrafficEnabled = trackingSettings.traffic_enabled ?? true;
            this.trackingCacheTtlSeconds = trackingSettings.cache_ttl_seconds ?? 60;
            this.trackingRouteCacheTtlSeconds = trackingSettings.route_cache_ttl_seconds ?? 600;
            this.trackingStaleLocationThresholdSeconds = trackingSettings.stale_location_threshold_seconds ?? 300;
            this.trackingDefaultVehicleSpeedKph = trackingSettings.default_vehicle_speed_kph ?? 35;
            this.lateDepartureAlertsEnabled = trackingSettings.alerts?.late_departures?.enabled ?? true;
            this.lateDepartureGracePeriodMinutes = trackingSettings.alerts?.late_departures?.grace_period_minutes ?? 15;
            this.routeDeviationAlertsEnabled = trackingSettings.alerts?.route_deviations?.enabled ?? true;
            this.routeDeviationDistanceThresholdMeters = trackingSettings.alerts?.route_deviations?.distance_threshold_meters ?? 500;
            this.prolongedStoppageAlertsEnabled = trackingSettings.alerts?.prolonged_stoppages?.enabled ?? true;
            this.prolongedStoppageDurationThresholdMinutes = trackingSettings.alerts?.prolonged_stoppages?.duration_threshold_minutes ?? 30;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadOsrmSettings() {
        try {
            const { api_host, system_host } = yield this.fetch.get('fleet-ops/settings/osrm-settings');
            this.osrmHost = api_host ?? '';
            this.osrmUseOwnServer = Boolean(api_host);
            this.osrmSystemHost = system_host ?? null;
        } catch {
            // Without the permission or the endpoint, the OSRM panel keeps its defaults.
        }
    }

    get trackingSettingsPayload() {
        return {
            traffic_enabled: this.trackingTrafficEnabled,
            cache_ttl_seconds: Number(this.trackingCacheTtlSeconds) || 60,
            route_cache_ttl_seconds: Number(this.trackingRouteCacheTtlSeconds) || 600,
            stale_location_threshold_seconds: Number(this.trackingStaleLocationThresholdSeconds) || 300,
            default_vehicle_speed_kph: Number(this.trackingDefaultVehicleSpeedKph) || 35,
            alerts: {
                late_departures: {
                    enabled: this.lateDepartureAlertsEnabled,
                    grace_period_minutes: Number(this.lateDepartureGracePeriodMinutes) || 15,
                },
                route_deviations: {
                    enabled: this.routeDeviationAlertsEnabled,
                    distance_threshold_meters: Number(this.routeDeviationDistanceThresholdMeters) || 500,
                },
                prolonged_stoppages: {
                    enabled: this.prolongedStoppageAlertsEnabled,
                    duration_threshold_minutes: Number(this.prolongedStoppageDurationThresholdMinutes) || 30,
                },
            },
        };
    }

    @action toggleTrackingAdvancedSettings() {
        this.showTrackingAdvancedSettings = !this.showTrackingAdvancedSettings;
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
