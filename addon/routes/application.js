import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getResourceNameFromTransition from '@fleetbase/ember-core/utils/get-resource-name-from-transition';

export default class ApplicationRoute extends Route {
    @service loader;
    @service fetch;
    @service intl;
    @service location;

    @action loading(transition) {
        const resourceName = getResourceNameFromTransition(transition, { humanize: true });
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: resourceName ? this.intl.t('fleet-ops.common.loading-resource', { resourceName }) : this.intl.t('fleet-ops.common.loading'),
        });
    }

    beforeModel() {
        this.location.getUserLocation();
    }

    model() {
        return this.fetch.get('fleet-ops/settings/visibility');
    }
}
