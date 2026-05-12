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
    @service mapSettings;

    @action loading(transition) {
        const resource = getResourceNameFromTransition(transition, { humanize: true });
        this.loader.showOnInitialTransition(transition, 'section.next-view-section', {
            loadingMessage: (resource ? this.intl.t('common.loading-resource', { resource }) : this.intl.t('common.loading')) + '...',
        });
    }

    async beforeModel() {
        if (this.abilities.cannot('fleet-ops see extension')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }

        await this.location.getUserLocation();
        await this.#loadRoutingSettings();
        await this.#loadTrackingSettings();
        await this.#loadMapSettings();
    }

    async #loadRoutingSettings() {
        const routingSetting = await this.fetch.get('fleet-ops/settings/routing-settings');
        this.currentUser.setOption('routing', routingSetting);
    }

    async #loadTrackingSettings() {
        const trackingSettings = await this.fetch.get('fleet-ops/settings/tracking-settings');
        this.currentUser.setOption('tracking', trackingSettings);
    }

    async #loadMapSettings() {
        await this.mapSettings.load();
    }
}
