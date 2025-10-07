import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class DeviceActionsService extends ResourceActionService {
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
                title: 'Create a new device',

                saveOptions: {
                    callback: this.refresh,
                },
                device,
            });
        },
        edit: (device) => {
            return this.resourceContextPanel.open({
                content: 'device/form',
                title: `Edit: ${device.name}`,

                device,
            });
        },
        view: (device) => {
            return this.resourceContextPanel.open({
                device,
                tabs: [
                    {
                        label: 'Overview',
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
                title: 'Create a new device',
                acceptButtonText: 'Create device',
                component: 'device/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', device, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (device, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: device,
                title: `Edit: ${device.name}`,
                acceptButtonText: 'Save Changes',
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
}
