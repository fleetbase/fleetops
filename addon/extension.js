import { MenuItem, MenuPanel, Widget, ExtensionComponent, Hook } from '@fleetbase/ember-core/contracts';

export default function (app, universe) {
    console.log('[FleetOps] Setting up extension...');

    // Get the menuService from the universe facade
    const menuService = universe.getService('menuService');
    
    // Register header menu item using menuService
    menuService.registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', { icon: 'route', priority: 0 });
}
