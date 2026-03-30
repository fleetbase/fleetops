/**
 * register-vroom-allocation
 *
 * Instance initializer that registers the VROOM allocation engine adapter
 * into the allocation-engine registry service.
 *
 * This pattern is identical to how register-osrm.js registers the OSRM
 * routing engine into the route-optimization registry.
 *
 * Third-party allocation engines follow the same pattern:
 *   1. Create a service extending AllocationEngineInterfaceService
 *   2. Create an instance initializer that calls allocationEngine.register()
 *   3. The engine will appear in the FleetOps allocation settings dropdown
 */
export function initialize(appInstance) {
    const allocationEngine = appInstance.lookup('service:allocation-engine');
    const vroomEngine      = appInstance.lookup('service:vroom-allocation-engine');

    if (allocationEngine && vroomEngine && !allocationEngine.has('vroom')) {
        allocationEngine.register('vroom', vroomEngine);
    }
}

export default {
    name: 'register-vroom-allocation',
    initialize,
};
