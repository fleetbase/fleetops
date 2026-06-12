import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';

export default class VehicleActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;
    @service fetch;
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
        this.initialize('vehicle', { status: 'available' });
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

    @action attachDevice(vehicle, options = {}) {
        this.modalsManager.show('modals/attach-device', {
            title: this.intl.t('vehicle.prompts.attach-device-title', { vehicleName: vehicle.displayName ?? vehicle.name }),
            acceptButtonText: this.intl.t('vehicle.actions.attach-device'),
            acceptButtonIcon: 'link',
            selectedDevice: null,
            vehicle,
            confirm: async (modal) => {
                const selectedDevice = modal.getOption('selectedDevice');

                if (!selectedDevice) {
                    return this.notifications.warning(this.intl.t('vehicle.prompts.select-device-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`vehicles/${vehicle.id}/attach-device`, { device: selectedDevice.id });
                    await vehicle.reload?.();
                    this.notifications.success(this.intl.t('vehicle.prompts.attach-device-success', { vehicleName: vehicle.displayName ?? vehicle.name }));
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

    @action async unassignOrders(vehicle, options = {}) {
        let response;

        try {
            response = await this.fetch.get(`vehicles/${vehicle.id}/assigned-orders`);
        } catch (error) {
            return this.notifications.serverError(error);
        }

        const currentOrderId = response.current;
        const orders = (response.orders ?? response.data ?? []).map((order) => ({
            ...order,
            is_current_job: [order.id, order.uuid, order.public_id].includes(currentOrderId),
        }));
        const vehicleName = vehicle.displayName ?? vehicle.display_name ?? vehicle.name;

        if (orders.length === 0) {
            return this.notifications.warning(this.intl.t('vehicle.prompts.no-assigned-orders-warning', { vehicleName }));
        }

        this.modalsManager.show('modals/assigned-orders', {
            title: this.intl.t('vehicle.prompts.unassign-orders-title', { vehicleName }),
            acceptButtonText: this.intl.t('vehicle.actions.unassign-orders'),
            acceptButtonIcon: 'truck-ramp-box',
            acceptButtonDisabled: true,
            subjectLabel: this.intl.t('resource.vehicle'),
            subjectName: vehicleName,
            orders,
            selectedOrderIds: [],
            toggleOrder: (order) => {
                const orderId = order.id;
                const selected = this.modalsManager.getOption('selectedOrderIds') ?? [];
                const selectedOrderIds = selected.includes(orderId) ? selected.filter((id) => id !== orderId) : [...selected, orderId];
                this.modalsManager.setOption('selectedOrderIds', selectedOrderIds);
                this.modalsManager.setOption('acceptButtonDisabled', selectedOrderIds.length === 0);
            },
            confirm: async (modal) => {
                const selectedOrderIds = modal.getOption('selectedOrderIds') ?? [];
                const selectedOrders = orders.filter((order) => selectedOrderIds.includes(order.id));

                if (selectedOrders.length === 0) {
                    return this.notifications.warning(this.intl.t('vehicle.prompts.select-orders-warning'));
                }

                const confirmed = await this.confirmUnassignOrders({
                    title: this.intl.t('vehicle.prompts.confirm-unassign-orders-title', { count: selectedOrders.length, vehicleName }),
                    body: this.intl.t('vehicle.prompts.confirm-unassign-orders-body', { vehicleName, orders: this.orderReferenceList(selectedOrders) }),
                });

                if (!confirmed) {
                    return;
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`vehicles/${vehicle.id}/unassign-orders`, { orders: selectedOrderIds });
                    await vehicle.reload?.();
                    this.notifications.success(this.intl.t('vehicle.prompts.unassign-orders-success', { count: selectedOrders.length, vehicleName }));
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

    orderReferenceList(orders) {
        const references = orders.map((order) => order.tracking ?? order.public_id ?? order.id).filter(Boolean);

        return references.length > 5 ? `${references.slice(0, 5).join(', ')} +${references.length - 5}` : references.join(', ');
    }

    async confirmUnassignOrders({ title, body }) {
        return new Promise((resolve) => {
            this.modalsManager.confirm({
                title,
                body,
                confirm: (_modal, done) => {
                    done();
                    resolve(true);
                },
                decline: (_modal, done) => {
                    done();
                    resolve(false);
                },
            });
        });
    }
}
