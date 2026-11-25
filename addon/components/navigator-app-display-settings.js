import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * What the organization shows its drivers in the Navigator app.
 */
export default class NavigatorAppDisplaySettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    @tracked settings = { showServiceRateToDrivers: true };
    @tracked loaded = false;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    @task *load() {
        try {
            const { navigatorAppSettings } = yield this.fetch.get('fleet-ops/settings/navigator-app-settings');
            this.settings = { ...this.settings, ...(navigatorAppSettings ?? {}) };
        } catch (error) {
            // Keep the defaults on screen; saving will report any real problem.
        }
        this.loaded = true;
    }

    @action toggleServiceRate(showServiceRateToDrivers) {
        this.settings = { ...this.settings, showServiceRateToDrivers };
    }

    @task *save() {
        try {
            const { navigatorAppSettings } = yield this.fetch.post('fleet-ops/settings/navigator-app-settings', { navigatorAppSettings: this.settings });
            this.settings = { ...this.settings, ...(navigatorAppSettings ?? {}) };
            this.notifications.success(this.intl.t('navigator-app-display-settings.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
