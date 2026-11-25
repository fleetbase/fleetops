import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class DriverOnboardSettingsComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;
    @tracked companyId;
    @tracked driverOnboardSettingsLoaded = false;
    @tracked driverOnboardSettings = {};
    @tracked driverOnboardMethods = ['invite', 'button'];
    @tracked join;

    constructor() {
        super(...arguments);
        this.companyId = this.currentUser.companyId;
        this.getDriverOnboardSettings.perform();
        this.loadJoinCode.perform();
    }

    get showsJoinCode() {
        return this.driverOnboardSettings?.enableDriverOnboardFromApp && this.driverOnboardSettings?.driverOnboardAppMethod === 'button' && this.join?.joinCode;
    }

    @task *loadJoinCode() {
        try {
            this.join = yield this.fetch.get('fleet-ops/settings/driver-join-code');
        } catch (error) {
            this.join = null;
        }
    }

    @task *rotateJoinCode() {
        try {
            this.join = yield this.fetch.post('fleet-ops/settings/driver-join-code');
            this.notifications.success(this.intl.t('driver-onboard-settings.join-code-replaced'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action downloadJoinQrCode() {
        if (!this.join?.qrCodeSvg) {
            return;
        }

        const url = URL.createObjectURL(new Blob([this.join.qrCodeSvg], { type: 'image/svg+xml' }));
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'navigator-driver-sign-up.svg';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    }

    @action enableDriverOnboard(enableDriverOnboardFromApp) {
        this.updateDriverOnboardSettings({ enableDriverOnboardFromApp });
    }

    @action enableDriverOnboardDocuments(driverMustProvideOnboardDocuments) {
        this.updateDriverOnboardSettings({ driverMustProvideOnboardDocuments });
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
        let driverOnboardSettingsResponse;

        try {
            driverOnboardSettingsResponse = yield this.fetch.post('fleet-ops/settings/driver-onboard-settings', { driverOnboardSettings });
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        if (driverOnboardSettingsResponse && driverOnboardSettings && driverOnboardSettings.enableDriverOnboardFromApp == false) {
            this.driverOnboardSettings = driverOnboardSettingsResponse.driverOnboardSettings;
        }

        // Saving may have switched self sign-up on, which is when a join code first exists.
        yield this.loadJoinCode.perform();
    }

    @task *getDriverOnboardSettings() {
        const companyId = this.currentUser.companyId;
        const { driverOnboardSettings } = yield this.fetch.get(`fleet-ops/settings/driver-onboard-settings/${companyId}`);
        this.driverOnboardSettings = driverOnboardSettings;

        if (this.companyDoesntHaveDriverOnboardSettings()) {
            this.updateDriverOnboardSettings({
                enableDriverOnboardFromApp: false,
                driverOnboardAppMethod: 'invite',
                driverMustProvideOnboardDocuments: false,
                requiredOnboardDocuments: [],
            });
        }

        this.driverOnboardSettingsLoaded = true;
    }

    companyDoesntHaveDriverOnboardSettings() {
        const companyId = this.driverOnboardSettings.companyId;
        return companyId === undefined;
    }

    updateDriverOnboardSettings(props = {}) {
        const companyId = this.currentUser.companyId;
        const driverOnboardSettings = this.driverOnboardSettings ?? {};
        this.driverOnboardSettings = {
            companyId: companyId,
            ...driverOnboardSettings,
            ...props,
        };
    }
}
