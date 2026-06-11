import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class DeviceActionsService extends ResourceActionService {
    @service fetch;

    constructor() {
        super(...arguments);
        this.initialize('device');
    }

    transition = {
        view: (device) => this.transitionTo('connectivity.devices.index.details', device),
        edit: (device) => this.transitionTo('connectivity.devices.index.edit', device),
        create: () => this.transitionTo('connectivity.devices.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const device = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'device/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.device')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                device,
            });
        },
        edit: (device) => {
            return this.resourceContextPanel.open({
                content: 'device/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: device.name }),
                useDefaultSaveTask: true,
                device,
            });
        },
        view: (device) => {
            return this.resourceContextPanel.open({
                device,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'device/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const device = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: device,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.device')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.device') }),
                component: 'device/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', device, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (device, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: device,
                title: this.intl.t('common.edit-resource-name', { resourceName: device.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'device/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', device, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (device, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: device,
                title: device.name,
                component: 'device/details',
                ...options,
            });
        },
    };

    @action attachToVehicle(device, options = {}) {
        this.modalsManager.show('modals/attach-telematic-device', {
            title: 'Attach device to vehicle',
            acceptButtonText: 'Attach Device',
            acceptButtonIcon: 'link',
            device,
            selectedVehicle: null,
            confirm: async (modal) => {
                const selectedVehicle = modal.getOption('selectedVehicle');

                if (!selectedVehicle) {
                    return this.notifications.warning('Select a vehicle to attach this device to.');
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`devices/${device.id}/attach`, { vehicle: selectedVehicle.id });
                    await device.reload?.();
                    this.notifications.success('Device attached to vehicle.');
                    modal.done();
                    this.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action detachFromVehicle(device, options = {}) {
        this.modalsManager.confirm({
            title: 'Detach device from vehicle?',
            body: `This will stop vehicle telemetry updates from ${device.displayName ?? device.name ?? 'this device'} until it is attached again.`,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`devices/${device.id}/detach`);
                    await device.reload?.();
                    this.notifications.success('Device detached from vehicle.');
                    modal.done();
                    this.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }
}
