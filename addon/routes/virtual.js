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
        const items = this.registryService.getRegistry('engine:fleet-ops');
        return items.find(item => {
            const slugMatch = item.slug === slug;
            const viewMatch = !view || item.view === view;
            const sectionMatch = !section || item.section === section;
            return slugMatch && viewMatch && sectionMatch;
        });
    }
}
