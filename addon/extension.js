import { MenuItem, MenuPanel, Widget, ExtensionComponent, Hook } from '@fleetbase/ember-core/contracts';

export default function (app, universe) {
    console.log('[FleetOps] Setting up extension...');

    const menuService = universe.getService('universe/menu-service');
    menuService.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', { icon: 'route', priority: 0 });
}
