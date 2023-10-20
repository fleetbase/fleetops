import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ApplicationRoute extends Route {
    @service loader;
    @service fetch;

    loading(transition) {
        const resourceName = this.getResouceName(transition);
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', { loadingMessage: resourceName ? `Loading ${resourceName}...` : `Loading...` });
    }

    model() {
        return this.fetch.get('fleet-ops/settings/visibility');
    }

    getResouceName(transition) {
        const { to } = transition;

        if (typeof to.name === 'string') {
            let routePathSegments = to.name.split('.');
            let resourceName = routePathSegments[3];

            return resourceName;
        }

        return null;
    }
}
