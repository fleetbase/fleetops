import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

/**
 * AllocationEngineService
 *
 * Registry service for allocation engine adapters. Mirrors the backend
 * OrchestrationEngineRegistry pattern. Engines register themselves from
 * instance initializers and the settings UI reads availableEngines to
 * populate the engine selector dropdown.
 *
 * This service is intentionally engine-agnostic — it has no knowledge of
 * VROOM or any other specific solver.
 */
export default class AllocationEngineService extends Service {
    /**
     * Map of registered engines keyed by identifier.
     * @type {Map<string, OrchestrationEngineInterfaceService>}
     */
    @tracked _engines = new Map();

    /**
     * Register an allocation engine adapter.
     *
     * @param {string}                            identifier  Unique engine key.
     * @param {OrchestrationEngineInterfaceService}  engine      Engine service instance.
     * @throws {Error} if an engine with the same identifier is already registered.
     */
    register(identifier, engine) {
        if (this._engines.has(identifier)) {
            throw new Error(`AllocationEngineService: engine '${identifier}' is already registered.`);
        }
        this._engines.set(identifier, engine);
    }

    /**
     * Resolve an engine by identifier.
     *
     * @param  {string} identifier
     * @returns {OrchestrationEngineInterfaceService}
     * @throws {Error} if no engine with the given identifier is registered.
     */
    resolve(identifier) {
        const engine = this._engines.get(identifier);
        if (!engine) {
            const available = [...this._engines.keys()].join(', ');
            throw new Error(`AllocationEngineService: no engine registered for '${identifier}'. Available: ${available}`);
        }
        return engine;
    }

    /**
     * Return all registered engines as an array of {id, name} objects.
     * Used by the settings UI to populate the engine selector dropdown.
     *
     * @returns {Array<{id: string, name: string}>}
     */
    get availableEngines() {
        return [...this._engines.values()].map((engine) => ({
            id: engine.identifier,
            name: engine.name,
        }));
    }

    /**
     * Check whether an engine with the given identifier is registered.
     *
     * @param  {string} identifier
     * @returns {boolean}
     */
    has(identifier) {
        return this._engines.has(identifier);
    }
}
