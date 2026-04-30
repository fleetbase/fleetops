import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
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
            yield this.performAdditionalSaveTasks();
            // Save in local memory too
            this.currentUser.setOption('routing', {
                router: this.displayEngine,
                routing_display_engine: this.displayEngine,
                routing_optimization_engine: this.optimizationEngine,
                unit: this.routingUnit,
            });
            this.notifications.success('Routing setting saved.');
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
        } catch (error) {
            this.notifications.serverError(error);
        }
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
