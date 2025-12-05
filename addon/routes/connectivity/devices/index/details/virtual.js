import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexDetailsVirtualRoute extends Route {
    @service universe;
    @service('universe/menu-service') menuService;

    queryParams = {
        view: {
            refreshModel: true,
        },
    };

    model({ section = null, slug }, transition) {
        const view = this.universe.getViewFromTransition(transition);
        return this.menuService.lookupMenuItem('fleet-ops:component:device:details', slug, view, section);
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.resource = this.modelFor('connectivity.devices.index.details');
    }
}
