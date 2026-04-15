import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Settings::OrchestratorController
 *
 * Manages the Orchestrator settings page at /settings/orchestrator.
 * Loads current settings, presents the engine selector dropdown (populated
 * from the allocation-engine registry), and saves changes.
 */
export default class SettingsOrchestratorController extends Controller {
    @service fetch;
    @service notifications;
    @service intl;
    @service('orchestration-engine') engineRegistry;

    @tracked allocationEngine = 'vroom';
    @tracked autoAllocateOnCreate = false;
    @tracked autoReallocateOnComplete = false;
    @tracked maxTravelTimeSeconds = 3600;
    @tracked balanceWorkload = false;
    @tracked isLoading = false;

    /**
     * Engine options for the selector dropdown.
     * Populated from the allocation-engine registry so new engines appear
     * automatically without modifying this controller.
     */
    get engineOptions() {
        return this.engineRegistry.availableEngines;
    }

    @task *loadSettings() {
        this.isLoading = true;
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/orchestrator-settings');
            this.allocationEngine = settings.allocation_engine ?? 'vroom';
            this.autoAllocateOnCreate = settings.auto_allocate_on_create ?? false;
            this.autoReallocateOnComplete = settings.auto_reallocate_on_complete ?? false;
            this.maxTravelTimeSeconds = settings.max_travel_time_seconds ?? 3600;
            this.balanceWorkload = settings.balance_workload ?? false;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isLoading = false;
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/orchestrator-settings', {
                allocation_engine: this.allocationEngine,
                auto_allocate_on_create: this.autoAllocateOnCreate,
                auto_reallocate_on_complete: this.autoReallocateOnComplete,
                max_travel_time_seconds: this.maxTravelTimeSeconds,
                balance_workload: this.balanceWorkload,
            });
            this.notifications.success(this.intl.t('orchestrator.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectEngine(engine) {
        this.allocationEngine = engine.id;
    }
}
