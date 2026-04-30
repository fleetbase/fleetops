import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

export default class AdminMapSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked googleMapsMapId = '';

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-map');
            this.googleMapsMapId = settings?.googleMapsMapId ?? '';
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/admin-map', {
                googleMapsMapId: this.googleMapsMapId,
            });

            this.notifications.success('Map settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
