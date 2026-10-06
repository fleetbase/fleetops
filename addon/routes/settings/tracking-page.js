import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsTrackingPageRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service fetch;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view tracking-page-settings')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    async model() {
        try {
            return await this.fetch.get('fleet-ops/settings/tracking-page-settings');
        } catch (error) {
            this.notifications.serverError(error);
            return null;
        }
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.load(model);
    }
}
