import Service from '@ember/service';

/**
 * OrchestrationEngineInterfaceService
 *
 * Base class that all allocation engine adapters must extend.
 * The interface mirrors the backend OrchestrationEngineInterface contract.
 *
 * Third-party engines extend this class, implement allocate(), and register
 * themselves via an instance initializer:
 *
 *   // addon/instance-initializers/register-my-engine.js
 *   export function initialize(appInstance) {
 *       const registry = appInstance.lookup('service:orchestration-engine');
 *       const engine   = appInstance.lookup('service:my-allocation-engine');
 *       registry.register('my-engine', engine);
 *   }
 *   export default { name: 'register-my-engine', initialize };
 *
 * @abstract
 */
export default class OrchestrationEngineInterfaceService extends Service {
    /**
     * Human-readable display name shown in the settings dropdown.
     * @type {string}
     */
    name = 'Unknown Engine';

    /**
     * Machine-readable identifier. Must be unique across all registered engines.
     * @type {string}
     */
    identifier = 'unknown';

    /**
     * Run the allocation algorithm.
     *
     * @param {Array}  orders   Array of order records to allocate.
     * @param {Array}  vehicles Array of vehicle records (with loaded driver).
     * @param {Object} options  Engine-specific options.
     * @returns {Promise<{assignments: Array, unassigned: Array, summary: Object}>}
     *
     * @abstract
     */
    // eslint-disable-next-line no-unused-vars
    async allocate(orders, vehicles, options = {}) {
        throw new Error(`OrchestrationEngineInterfaceService: allocate() must be implemented by ${this.constructor.name}`);
    }
}
