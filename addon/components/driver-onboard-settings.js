import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency-decorators';

export default class DriverOnboardSettingsComponent extends Component {
    @service fetch;
    @service currentUser;
    @tracked companyId;
    @tracked driverOnboardSettings = {};
    @tracked driverOnboardMethods = ['invite', 'button'];

    constructor() {
        super(...arguments);
        this.companyId = this.currentUser.companyId;
        this.getDriverOnboardSettings.perform();
    }

    @action enableDriverOnboard(enableDriverOnboardFromApp) {
        this.updateDriverOnboardSettings({ enableDriverOnboardFromApp });
    }

    @action enableDriverOnboardDocuments(driverMustProvideOnboardDoucments) {
        this.updateDriverOnboardSettings({ driverMustProvideOnboardDoucments });
    }

    @action selectDriverOnboardMethod(driverOnboardAppMethod) {
        this.updateDriverOnboardSettings({ driverOnboardAppMethod });
    }

    @action onOnboardDocumentsChanged(requiredOnboardDocuments) {
        requiredOnboardDocuments = requiredOnboardDocuments.filter((documentName) => typeof documentName === 'string');
        this.updateDriverOnboardSettings({ requiredOnboardDocuments });
    }

    @task *saveDriverOnboardSettings() {
        const { driverOnboardSettings } = this;
        yield this.fetch.post('fleet-ops/settings/driver-onboard-settings', { driverOnboardSettings });
    }

    @task *getDriverOnboardSettings() {
        const { driverOnboardSettings } = yield this.fetch.get('settings/driver-onboard-settings');
        this.driverOnboardSettings = driverOnboardSettings;

        if (this.companyDoesntHaveDriverOnboardSettings()) {
            this.updateDriverOnboardSettings({
                enableDriverOnboardFromApp: false,
                driverOnboardAppMethod: 'invite',
                driverMustProvideOnboardDoucments: false,
                requiredOnboardDocuments: [],
            });
        }
    }

    companyDoesntHaveDriverOnboardSettings() {
        const companyId = this.currentUser.companyId;
        return this.driverOnboardSettings[companyId] === undefined;
    }

    updateDriverOnboardSettings(props = {}) {
        const companyId = this.currentUser.companyId;
        const driverOnboardSettings = this.driverOnboardSettings[companyId] ?? {};
        this.driverOnboardSettings = {
            [companyId]: {
                ...driverOnboardSettings,
                ...props,
            },
        };
    }
}
