import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VirtualRoute extends Route {
    @service universe;
    @service('universe/registry-service') registryService;

    queryParams = {
        view: {
            refreshModel: true,
        },
    };

    model({ section = null, slug }, transition) {
        const view = this.universe.getViewFromTransition(transition);
        return this.registryService.lookupMenuItem('engine:fleet-ops', slug, view, section);
    }
}
