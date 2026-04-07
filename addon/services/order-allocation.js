import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

/**
 * OrderAllocationService
 *
 * Orchestrates the full allocation workflow:
 *   1. Fetch unassigned orders and available vehicles
 *   2. Run the active allocation engine (via the allocation-engine registry)
 *   3. Present the proposed plan to the dispatcher
 *   4. Commit confirmed assignments to the backend
 *
 * This service is injected into the Dispatcher Workbench component and the
 * allocation settings controller.
 */
export default class OrderAllocationService extends Service {
    @service fetch;
    @service store;
    @service notifications;
    @service intl;
    @service('allocation-engine') engineRegistry;

    /** The proposed allocation plan returned by the last run. */
    @tracked currentPlan = null;

    /** Whether an allocation run is in progress. */
    @tracked isRunning = false;

    /** The identifier of the currently active engine (from settings). */
    @tracked activeEngineId = 'vroom';

    /**
     * Load allocation settings from the backend.
     */
    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/allocation/settings');
            this.activeEngineId = settings.allocation_engine ?? 'vroom';
            return settings;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Save allocation settings to the backend.
     *
     * @param {Object} settings
     */
    @task *saveSettings(settings) {
        try {
            yield this.fetch.patch('fleet-ops/allocation/settings', settings);
            this.notifications.success(this.intl.t('allocation.settings-saved'));
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Run the allocation engine and store the proposed plan.
     *
     * @param {Array}  orderIds   Optional list of order public_ids to allocate.
     * @param {Array}  vehicleIds Optional list of vehicle public_ids to use.
     * @param {Object} options    Engine-specific options.
     */
    @task *run(orderIds = [], vehicleIds = [], options = {}) {
        this.isRunning = true;
        try {
            const result = yield this.fetch.post('fleet-ops/allocation/run', {
                order_ids:   orderIds,
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
     * Commit a (possibly modified) allocation plan.
     * The dispatcher may have adjusted assignments via drag-and-drop before
     * calling commit.
     *
     * @param {Array} assignments  Array of {order_id, vehicle_id, driver_id, sequence}
     */
    @task *commit(assignments) {
        try {
            const result = yield this.fetch.post('fleet-ops/allocation/commit', { assignments });
            this.notifications.success(
                this.intl.t('allocation.committed', { count: result.committed?.length ?? 0 })
            );
            this.currentPlan = null;
            return result;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }

    /**
     * Fetch the list of available allocation engines from the backend.
     * Used to cross-validate that the backend and frontend registries are in sync.
     */
    @task *fetchAvailableEngines() {
        try {
            const response = yield this.fetch.get('fleet-ops/allocation/engines');
            return response.engines ?? [];
        } catch (error) {
            return [];
        }
    }
}
