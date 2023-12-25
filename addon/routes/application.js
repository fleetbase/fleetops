import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getResourceNameFromTransition from '@fleetbase/ember-core/utils/get-resource-name-from-transition';

export default class ApplicationRoute extends Route {
    @service loader;
    @service fetch;

    @action loading(transition) {
        const resourceName = getResourceNameFromTransition(transition);
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', { loadingMessage: resourceName ? `Loading ${resourceName}...` : `Loading...` });
    }

    model() {
        return this.fetch.get('fleet-ops/settings/visibility');
    }
}
