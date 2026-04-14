import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Settings::OrchestratorRoute
 *
 * Entry point for the allocation settings page at /settings/order-allocation.
 * Loads current settings into the controller on entry.
 */
export default class SettingsOrchestratorRoute extends Route {
    @service notifications;
    @service abilities;
    @service intl;
    @service hostRouter;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    setupController(controller) {
        super.setupController(...arguments);
        controller.loadSettings.perform();
    }
}
