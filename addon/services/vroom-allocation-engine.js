import { inject as service } from '@ember/service';
import OrchestrationEngineInterfaceService from './orchestration-engine-interface';

/**
 * VroomOrchestrationEngineService
 *
 * Frontend adapter for the VROOM orchestration engine. Delegates all computation
 * to the backend OrchestrationController — the frontend adapter's role is to
 * call the correct API endpoint and return the normalized result.
 *
 * This service is registered into the allocation-engine registry via the
 * register-vroom-allocation instance initializer.
 */
export default class VroomOrchestrationEngineService extends OrchestrationEngineInterfaceService {
    @service fetch;
    @service notifications;

    name = 'VROOM';
    identifier = 'vroom';

    /**
     * Run the VROOM orchestration via the backend API.
     *
     * @param {Array}  orders   Array of order records (or order public_ids).
     * @param {Array}  vehicles Array of vehicle records (or vehicle public_ids).
     * @param {Object} options  Options forwarded to the backend engine.
     * @returns {Promise<{assignments: Array, unassigned: Array, summary: Object}>}
     */
    async allocate(orders = [], vehicles = [], options = {}) {
        const orderIds = orders.map((o) => (typeof o === 'string' ? o : o.public_id));
        const vehicleIds = vehicles.map((v) => (typeof v === 'string' ? v : v.public_id));

        try {
            const result = await this.fetch.post('fleet-ops/orchestrator/run', {
                order_ids: orderIds,
                vehicle_ids: vehicleIds,
                options: { ...options, engine: 'vroom' },
            });
            return result;
        } catch (error) {
            this.notifications.serverError(error);
            throw error;
        }
    }
}
