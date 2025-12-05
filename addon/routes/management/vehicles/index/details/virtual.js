import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementVehiclesIndexDetailsVirtualRoute extends Route {
    @service universe;
    @service('universe/menu-service') menuService;

    queryParams = {
        view: {
            refreshModel: true,
        },
    };

    model({ section = null, slug }, transition) {
        const view = this.universe.getViewFromTransition(transition);
        return this.menuService.lookupMenuItem('fleet-ops:component:vehicle:details', slug, view, section);
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.resource = this.modelFor('management.vehicles.index.details');
    }
}
