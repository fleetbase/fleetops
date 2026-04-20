import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsRoute extends Route {
    @service('universe/menu-service') menuService;
    @service universe;

    beforeModel(transition) {
        if (transition.intent && transition.intent.url) {
            // Here we will check if it's an actual virtual route instead of operations
            const intendedUrl = transition.intent.url;
            const intentSegments = intendedUrl.split('/');
            // Needs to match for section and slug
            const section = intentSegments[2];
            const slug = intentSegments[3];
            // This is not a operations route check menu service for a virtual registration match
            if (section !== 'operations') {
                const menuItem = this.menuService.lookupMenuItem('engine:fleet-ops', slug, null, section);
                if (menuItem) {
                    return this.universe.transitionMenuItem('console.fleet-ops.virtual', menuItem);
                }
            }
        }
    }
}
