import { MenuItem, MenuPanel, Widget, ExtensionComponent, Hook } from '@fleetbase/ember-core/contracts';

export default function (app, universe) {
    console.log('[FleetOps] Setting up extension...');

    universe.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', { icon: 'route', priority: 0 });
}
