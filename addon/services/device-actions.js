import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class DeviceActionsService extends ResourceActionService {
    @service fetch;
    @service('universe/menu-service') menuService;

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
            if (!device?.id) {
                return this.notifications?.warning?.(this.intl.t('common.invalid-resource'));
            }

            const registeredTabs = this.menuService?.getMenuItems?.('fleet-ops:component:device:details');

            return this.resourceContextPanel.open({
                device,
                header: 'device/panel-header',
                tabs: [
                    {
                        key: 'overview',
                        id: 'overview',
                        label: this.intl.t('common.overview'),
                        component: 'device/details',
                    },
                    {
                        key: 'vehicle',
                        id: 'vehicle',
                        label: this.intl.t('resource.vehicle'),
                        component: 'device/panel-tabs/vehicle',
                    },
                    {
                        key: 'sensors',
                        id: 'sensors',
                        label: this.intl.t('resource.sensors'),
                        component: 'device/panel-tabs/sensors',
                    },
                    {
                        key: 'events',
                        id: 'events',
                        label: this.intl.t('resource.device-events'),
                        component: 'device/panel-tabs/events',
                    },
                    ...(isArray(registeredTabs) ? registeredTabs.filter((tab) => tab.component || tab.render) : []),
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
            title: this.intl.t('device.prompts.attach-to-vehicle-title'),
            acceptButtonText: this.intl.t('device.actions.attach-to-vehicle'),
            acceptButtonIcon: 'link',
            device,
            selectedVehicle: null,
            confirm: async (modal) => {
                const selectedVehicle = modal.getOption('selectedVehicle');

                if (!selectedVehicle) {
                    return this.notifications.warning(this.intl.t('device.prompts.select-vehicle-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`devices/${device.id}/attach`, { vehicle: selectedVehicle.id });
                    await device.reload?.();
                    this.notifications.success(this.intl.t('device.prompts.attach-to-vehicle-success'));
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
        const deviceName = device.displayName ?? device.name ?? this.intl.t('resource.device');
        const vehicleName = device.attached_to_name ?? device.attachable?.display_name ?? device.attachable?.name;

        if (!device.attachable_uuid && !vehicleName) {
            return this.notifications.warning(this.intl.t('device.prompts.not-attached-warning', { deviceName }));
        }

        this.modalsManager.confirm({
            title: this.intl.t('device.prompts.detach-from-vehicle-title', { deviceName }),
            body: this.intl.t('device.prompts.detach-from-vehicle-body', { deviceName, vehicleName: vehicleName ?? this.intl.t('resource.vehicle') }),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`devices/${device.id}/detach`);
                    await device.reload?.();
                    this.notifications.success(this.intl.t('device.prompts.detach-from-vehicle-success'));
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
