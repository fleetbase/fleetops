/**
 * register-vroom-allocation
 *
 * Instance initializer that registers the VROOM orchestration engine adapter
 * into the orchestration-engine registry service.
 *
 * This pattern is identical to how register-osrm.js registers the OSRM
 * routing engine into the route-optimization registry.
 *
 * Third-party orchestration engines follow the same pattern:
 *   1. Create a service extending OrchestrationEngineInterfaceService
 *   2. Create an instance initializer that calls orchestrationEngine.register()
 *   3. The engine will appear in the FleetOps orchestrator settings dropdown
 */
export function initialize(appInstance) {
    const orchestrationEngine = appInstance.lookup('service:orchestration-engine');
    const vroomEngine = appInstance.lookup('service:vroom-allocation-engine');

    if (orchestrationEngine && vroomEngine && !orchestrationEngine.has('vroom')) {
        orchestrationEngine.register('vroom', vroomEngine);
    }
}

export default {
    name: 'register-vroom-allocation',
    initialize,
};
