import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import getResourceNameFromTransition from '@fleetbase/ember-core/utils/get-resource-name-from-transition';

export default class ApplicationRoute extends Route {
    @service loader;
    @service intl;
    @service location;
    @service abilities;
    @service hostRouter;
    @service notifications;
    @service fetch;
    @service currentUser;

    @action loading(transition) {
        const resource = getResourceNameFromTransition(transition, { humanize: true });
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: (resource ? this.intl.t('common.loading-resource', { resource }) : this.intl.t('common.loading')) + '...',
        });
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        this.location.getUserLocation();
        this.#loadRoutingSettings();
    }

    async #loadRoutingSettings() {
        const routingSetting = await this.fetch.get('fleet-ops/settings/routing-settings');
        this.currentUser.setOption('routing', routingSetting);
    }
}
