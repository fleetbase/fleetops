import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class SettingsRoutingController extends Controller {
    @service fetch;
    @service notifications;
    @service currentUser;
    @service leafletRouterControl;
    @tracked routerService = 'osrm';

    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    /**
     * Save routing settings.
     *
     * @memberof SettingsRoutingController
     */
    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/routing-settings', { router: this.routerService });
            // Save in local memory too
            this.currentUser.setOption('routing', { router: this.routerService });
            this.notifications.success('Routing setting saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Get routing settings.
     *
     * @memberof SettingsRoutingController
     */
    @task *getSettings() {
        try {
            const { router } = yield this.fetch.get('fleet-ops/settings/routing-settings');
            this.routerService = router;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
