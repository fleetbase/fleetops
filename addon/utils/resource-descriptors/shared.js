import { buildSharedResourceDescriptors, guardOrder } from '@fleetbase/fleetops-data/utils/resource-descriptors';
import { panelOpener } from './helpers';

/**
 * The descriptors `@fleetbase/fleetops-data` shares with every extension,
 * opened through this engine's own action services instead of through a
 * lazy engine load: inside FleetOps the services are already here, and the
 * registry keeps these richer versions over the shared ones.
 */
export function sharedDescriptors(owner, keys) {
    const byKey = Object.fromEntries(buildSharedResourceDescriptors(owner).map((descriptor) => [descriptor.key, descriptor]));

    return keys.map((key) => {
        const open = panelOpener(owner, `${key}-actions`);

        return { ...byKey[key], canOpen: undefined, open: key === 'order' ? guardOrder(open) : open };
    });
}
