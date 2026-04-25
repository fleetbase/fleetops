import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

/**
 * OrderAllocationService
 *
 * Orchestrates the full allocation workflow:
 *   1. Fetch unassigned orders and available vehicles
 *   2. Run the active orchestration engine (via the orchestration-engine registry)
 *   3. Present the proposed plan to the dispatcher
 *   4. Commit confirmed assignments to the backend
 *
 * This service is injected into the Dispatcher Workbench component and the
 * orchestrator settings controller.
 */
export default class OrderAllocationService extends Service {
    @service fetch;
    @service store;
    @service notifications;
    @service intl;

    /** The proposed allocation plan returned by the last run. */
    @tracked currentPlan = null;

    /** Whether an allocation run is in progress. */
    @tracked isRunning = false;

    /** The identifier of the currently active engine (from settings). */
    @tracked activeEngineId = 'greedy';

    /**
     * Load orchestrator settings from the backend.
     */
    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/orchestrator-settings');
            this.activeEngineId = settings.orchestrator_engine ?? 'greedy';
            return settings;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Save orchestrator settings to the backend.
     *
     * @param {Object} settings
     */
    @task *saveSettings(settings) {
        try {
            yield this.fetch.post('fleet-ops/settings/orchestrator-settings', settings);
            this.notifications.success(this.intl.t('orchestrator.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Run the orchestration engine and store the proposed plan.
     *
     * @param {Array}  orderIds   Optional list of order public_ids to allocate.
     * @param {Array}  vehicleIds Optional list of vehicle public_ids to use.
     * @param {Object} options    Engine-specific options.
     */
    @task *run(orderIds = [], vehicleIds = [], options = {}) {
        this.isRunning = true;
        try {
            const result = yield this.fetch.post('fleet-ops/orchestrator/run', {
                order_ids: orderIds,
                vehicle_ids: vehicleIds,
                options,
            });
            this.currentPlan = result;
            return result;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        } finally {
            this.isRunning = false;
        }
    }

    /**
     * Commit a (possibly modified) orchestration plan.
     * The dispatcher may have adjusted assignments via drag-and-drop before
     * calling commit.
     *
     * @param {Array} assignments  Array of {order_id, vehicle_id, driver_id, sequence}
     */
    @task *commit(assignments) {
        try {
            const result = yield this.fetch.post('fleet-ops/orchestrator/commit', { assignments });
            this.notifications.success(this.intl.t('orchestrator.committed', { count: result.committed?.length ?? 0 }));
            this.currentPlan = null;
            return result;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Fetch the list of available orchestration engines from the backend.
     * Used to cross-validate that the backend and frontend registries are in sync.
     */
    @task *fetchAvailableEngines() {
        try {
            const response = yield this.fetch.get('fleet-ops/orchestrator/engines');
            return response.engines ?? [];
        } catch (error) {
            return [];
        }
    }
}
