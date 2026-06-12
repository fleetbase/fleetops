import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class DeviceManagerComponent extends Component {
    @service store;
    @service fetch;
    @service modalsManager;
    @service notifications;
    @service intl;
    @tracked devices = [];

    get resourceName() {
        const record = this.args.resource;
        if (!record) return 'resource';

        return (
            get(record, this.args.namePath ?? 'name') ??
            get(record, 'display_name') ??
            get(record, 'displayName') ??
            get(record, 'tracking') ??
            get(record, 'public_id') ??
            getModelName(record)
        );
    }

    constructor() {
        super(...arguments);
        this.loadDevices.perform();
    }

    @action addDevice() {
        this.modalsManager.show('modals/attach-device', {
            title: this.intl.t('device.prompts.select-device-to-attach-title', { resourceName: this.resourceName }),
            acceptButtonText: this.intl.t('device.prompts.confirm-attach-device'),
            selectedDevice: null,
            confirm: async (modal) => {
                const selectedDevice = modal.getOption('selectedDevice');
                if (!selectedDevice) return;

                modal.startLoading();

                try {
                    await this.fetch.post(`vehicles/${this.args.resource.id}/attach-device`, { device: selectedDevice.id });
                    await this.loadDevices.perform();
                    this.notifications.success(this.intl.t('device.prompts.attach-device-success'));
                    modal.done();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    @action removeDevice(device) {
        const deviceName = device.displayName ?? device.name ?? device.device_id ?? this.intl.t('resource.device');

        this.modalsManager.confirm({
            title: this.intl.t('device.prompts.detach-from-resource-title', { deviceName, resourceName: this.resourceName }),
            body: this.intl.t('device.prompts.detach-from-resource-body', { deviceName, resourceName: this.resourceName, resourceType: getModelName(this.args.resource) }),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`vehicles/${this.args.resource.id}/detach-device`, { device: device.id });
                    await this.loadDevices.perform();
                    this.notifications.success(this.intl.t('device.prompts.detach-from-resource-success', { deviceName, resourceName: this.resourceName }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @task *loadDevices() {
        if (!this.args.resource) return;

        try {
            const devices = yield this.store.query('device', { attachable_uuid: this.args.resource.id });
            this.devices = devices;
        } catch (err) {
            debug('Unable to load devices: ' + err.message);
        }
    }
}
