import { buildFleetOpsResourceDescriptors } from '../utils/resource-descriptors';

/**
 * Registers every FleetOps resource descriptor with the resource registry
 * ember-ui provides. Runs with the engine instance, so the descriptors'
 * open actions reach the engine's action services and router. A host whose
 * ember-ui predates the registry has no `resource-registry` service, and
 * the identity components simply fall back to text.
 */
export function initialize(engineInstance) {
    let registry;

    try {
        registry = engineInstance.lookup('service:resource-registry');
    } catch {
        registry = null;
    }

    if (!registry || typeof registry.registerDescriptors !== 'function') {
        return;
    }

    registry.registerDescriptors(buildFleetOpsResourceDescriptors(engineInstance));
}

export default {
    name: 'register-fleetops-resource-descriptors',
    initialize,
};
