import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, get } from '@ember/object';
import { inject as service } from '@ember/service';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';

export default class DeviceManagerComponent extends Component {
    @service store;
    @service modalsManager;
    @service notifications;
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
            title: 'Select device to attach',
            acceptButtonText: 'Confirm & Attach Device',
            selectedDevice: null,
            confirm: async (modal) => {
                const selectedDevice = modal.getOption('selectedDevice');
                if (!selectedDevice) return;

                selectedDevice.setProperties({
                    attachable_uuid: this.args.resource.id,
                    attachable_type: `fleet-ops:${getModelName(this.args.resource)}`,
                });

                modal.startLoading();

                try {
                    await selectedDevice.save();
                    await this.loadDevices.perform();
                    this.notifications.success('Device attached successfully.');
                    modal.done();
                } catch (err) {
                    this.notifications.serverError(err);
                    modal.stopLoading();
                }
            },
        });
    }

    @action removeDevice(device) {
        this.modalsManager.confirm({
            title: `Are you sure you want to detach this device (${device.name}) from (${this.resourceName})?`,
            body: `Removing this device will stop all telematic updates and events for this ${getModelName(this.args.resource)}.`,
            confirm: async (modal) => {
                modal.startLoading();

                device.setProperties({ attachable_uuid: null, attachable_type: null });

                try {
                    await device.save();
                    await this.loadDevices.perform();
                    this.notifications.success('Device removed.');
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
