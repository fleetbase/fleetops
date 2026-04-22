import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';

export default class VehicleActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;
    @service maintenanceScheduleActions;
    @service workOrderActions;
    @service maintenanceActions;

    get registeredTabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:vehicle:details');
        return (isArray(registeredTabs) ? registeredTabs : [])
            .map((tab) => {
                delete tab.route;
                if (!tab.key) {
                    tab.key = tab.id ?? dasherize(tab.label ?? tab.title);
                }
                return tab;
            })
            .filter((tab) => !tab.component);
    }

    get panelTabs() {
        return [
            {
                key: 'overview',
                label: 'Overview',
                component: 'vehicle/details',
            },
            {
                key: 'positions',
                label: 'Positions',
                component: 'positions-replay',
            },
            {
                key: 'devices',
                label: 'Devices',
                component: 'device/manager',
            },
            {
                key: 'schedules',
                label: 'Schedules',
                component: 'vehicle/details/schedules',
            },
            {
                key: 'work-orders',
                label: 'Work Orders',
                component: 'vehicle/details/work-orders',
            },
            {
                key: 'maintenance-history',
                label: 'Maintenance',
                component: 'vehicle/details/maintenance-history',
            },
            ...this.registeredTabs,
        ];
    }

    constructor() {
        super(...arguments);
        this.initialize('vehicle', { status: 'active' });
    }

    transition = {
        view: (vehicle) => this.transitionTo('management.vehicles.index.details', vehicle),
        edit: (vehicle) => this.transitionTo('management.vehicles.index.edit', vehicle),
        create: () => this.transitionTo('management.vehicles.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const vehicle = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'vehicle/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.vehicle')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                vehicle,
                ...options,
            });
        },
        edit: async (vehicle, options = {}) => {
            if (vehicle?.meta?._index_resource) {
                await vehicle.reload();
            }

            return this.resourceContextPanel.open({
                content: 'vehicle/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: vehicle.name }),
                actionButtons: [
                    {
                        icon: 'eye',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.view(vehicle);
                        },
                    },
                ],
                useDefaultSaveTask: true,
                vehicle,
                ...options,
            });
        },
        view: async (vehicle, options = {}) => {
            if (vehicle?.meta?._index_resource) {
                await vehicle.reload();
            }

            return this.resourceContextPanel.open({
                vehicle,
                header: 'vehicle/panel-header',
                actionButtons: [
                    {
                        icon: 'pencil',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.edit(vehicle);
                        },
                    },
                ],
                tabs: this.panelTabs,
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const vehicle = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.vehicle')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.vehicle') }),
                component: 'vehicle/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vehicle, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: async (vehicle, options = {}, saveOptions = {}) => {
            if (vehicle?.meta?._index_resource) {
                await vehicle.reload();
            }

            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: this.intl.t('common.edit-resource-name', { resourceName: vehicle.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'vehicle/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', vehicle, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: async (vehicle, options = {}) => {
            if (vehicle?.meta?._index_resource) {
                await vehicle.reload();
            }

            return this.modalsManager.show('modals/resource', {
                resource: vehicle,
                title: vehicle.name,
                component: 'vehicle/details',
                ...options,
            });
        },
    };

    @action scheduleMaintenance(vehicle) {
        this.maintenanceScheduleActions.modal.create({ subject: vehicle });
    }

    @action createWorkOrder(vehicle) {
        this.workOrderActions.modal.create({ target: vehicle });
    }

    @action logMaintenance(vehicle) {
        this.maintenanceActions.modal.create({ maintainable: vehicle });
    }

    @action locate(vehicle, options = {}) {
        const { latitude, longitude, location } = vehicle;

        this.modalsManager.show('modals/point-map', {
            title: this.intl.t('common.resource-location', { resource: vehicle.displayName }),
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            resource: vehicle,
            popupText: `${vehicle.displayName} (${vehicle.public_id})`,
            tooltip: vehicle.positionString,
            icon: leafletIcon({
                iconUrl: vehicle.avatar ?? config?.defaultValues?.vehicleAvatar,
                iconSize: [40, 40],
            }),
            latitude,
            longitude,
            location,
            ...options,
        });
    }
}
