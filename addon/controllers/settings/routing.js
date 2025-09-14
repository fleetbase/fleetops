import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class SettingsRoutingController extends Controller {
    @service fetch;
    @service notifications;
    @service currentUser;
    @service leafletRoutingControl;
    @tracked routerService = 'osrm';
    @tracked routingUnit = 'km';
    @tracked routingUnitOptions = [
        { label: 'Kilometers', value: 'km' },
        { label: 'Miles', value: 'mi' },
    ];
    @tracked saveTasks = [];

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
            yield this.fetch.post('fleet-ops/settings/routing-settings', { router: this.routerService, unit: this.routingUnit });
            yield this.performAdditionalSaveTasks();
            // Save in local memory too
            this.currentUser.setOption('routing', { router: this.routerService, unit: this.routingUnit });
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
            const { router, unit } = yield this.fetch.get('fleet-ops/settings/routing-settings');
            this.routerService = router;
            this.routingUnit = unit;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    registerSaveTask(task) {
        this.saveTasks.push(task);
    }

    async performAdditionalSaveTasks() {
        for (let i = 0; i < this.saveTasks.length; i++) {
            const task = this.saveTasks[i];
            if (typeof task.perform === 'function') {
                await task.perform();
            }
        }
        return true;
    }
}
