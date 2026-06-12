import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { dasherize } from '@ember/string';

export default class DriverActionsService extends ResourceActionService {
    @service('universe/menu-service') menuService;
    @service fetch;

    get registeredTabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:driver:details');
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
                label: this.intl.t('common.overview'),
                component: 'driver/details',
            },
            {
                key: 'positions',
                label: 'Positions',
                component: 'positions-replay',
            },
            {
                key: 'schedule',
                label: this.intl.t('common.schedule'),
                component: 'driver/schedule',
            },
            ...this.registeredTabs,
        ];
    }

    constructor() {
        super(...arguments);
        this.initialize('driver');
    }

    transition = {
        view: (driver) => this.transitionTo('management.drivers.index.details', driver),
        edit: (driver) => this.transitionTo('management.drivers.index.edit', driver),
        create: () => this.transitionTo('management.drivers.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const driver = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'driver/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.driver')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                driver,
                ...options,
            });
        },
        edit: async (driver, options = {}) => {
            if (driver?.meta?._index_resource) {
                await driver.reload();
            }

            return this.resourceContextPanel.open({
                content: 'driver/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: driver.name }),
                actionButtons: [
                    {
                        icon: 'eye',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.view(driver);
                        },
                    },
                ],
                useDefaultSaveTask: true,
                driver,
                ...options,
            });
        },
        view: async (driver, options = {}) => {
            if (driver?.meta?._index_resource) {
                await driver.reload();
            }

            return this.resourceContextPanel.open({
                driver,
                header: 'driver/panel-header',
                actionButtons: [
                    {
                        icon: 'pencil',
                        fn: async () => {
                            await this.resourceContextPanel.closeAll();
                            this.panel.edit(driver);
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
            const driver = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: driver,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.driver')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.driver') }),
                component: 'driver/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', driver, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: async (driver, options = {}, saveOptions = {}) => {
            if (driver?.meta?._index_resource) {
                await driver.reload();
            }

            return this.modalsManager.show('modals/resource', {
                resource: driver,
                title: this.intl.t('common.edit-resource-name', { resourceName: driver.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'driver/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', driver, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: async (driver, options = {}) => {
            if (driver?.meta?._index_resource) {
                await driver.reload();
            }

            return this.modalsManager.show('modals/resource', {
                resource: driver,
                title: driver.name,
                component: 'driver/details',
                ...options,
            });
        },
    };

    @action locate(driver, options = {}) {
        const { latitude, longitude, location } = driver;

        this.modalsManager.show('modals/point-map', {
            title: this.intl.t('common.resource-location', { resource: driver.name }),
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            resource: driver,
            popupText: `${driver.name} (${driver.public_id})`,
            tooltip: driver.positionString,
            icon: leafletIcon({
                iconUrl: driver.vehicle_avatar ?? config?.defaultValues?.vehicleAvatar,
                iconSize: [40, 40],
            }),
            latitude,
            longitude,
            location,
            ...options,
        });
    }

    @action assignOrder(driver, options = {}) {
        this.modalsManager.show('modals/driver-assign-order', {
            title: this.intl.t('driver.prompts.assign-order-title', { driverName: driver.name }),
            acceptButtonText: this.intl.t('driver.actions.assign-order'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            acceptButtonDisabled: true,
            hideDeclineButton: true,
            selectedOrder: null,
            selectOrder: (order) => {
                this.modalsManager.setOption('selectedOrder', order);
                this.modalsManager.setOption('acceptButtonDisabled', false);
            },
            driver,
            confirm: async (modal) => {
                const selectedOrder = modal.getOption('selectedOrder');
                if (!selectedOrder) {
                    return this.notifications.warning(this.intl.t('driver.prompts.select-order-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`drivers/${driver.id}/assign-order`, { order: selectedOrder.id });
                    await driver.reload?.();
                    this.notifications.success(this.intl.t('driver.prompts.assign-order-success', { driverName: driver.name }));
                    modal.done();
                    this.refresh();
                } catch (err) {
                    this.notifications.serverError(err);
                    driver.rollbackAttributes();
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action assignVehicle(driver, options = {}) {
        this.modalsManager.show('modals/driver-assign-vehicle', {
            title: this.intl.t('driver.prompts.assign-vehicle-title', { driverName: driver.name }),
            acceptButtonText: this.intl.t('driver.actions.assign-vehicle'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            driver,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    const vehicleId = driver.vehicle_uuid ?? driver.vehicle?.id;
                    if (!vehicleId) {
                        modal.stopLoading();
                        return this.notifications.warning(this.intl.t('driver.prompts.select-vehicle-warning'));
                    }

                    await this.fetch.post(`drivers/${driver.id}/assign-vehicle`, { vehicle: vehicleId });
                    await driver.reload?.();
                    this.notifications.success(this.intl.t('driver.prompts.assign-vehicle-success', { driverName: driver.name }));
                    modal.done();
                    this.refresh();
                } catch (err) {
                    this.notifications.serverError(err);
                    driver.rollbackAttributes();
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action unassignOrder(driver, options = {}) {
        return this.unassignOrders(driver, options);
    }

    @action async unassignOrders(driver, options = {}) {
        let response;

        try {
            response = await this.fetch.get(`drivers/${driver.id}/assigned-orders`);
        } catch (error) {
            return this.notifications.serverError(error);
        }

        const currentOrderId = response.current;
        const orders = (response.orders ?? response.data ?? []).map((order) => ({
            ...order,
            is_current_job: [order.id, order.uuid, order.public_id].includes(currentOrderId),
        }));

        if (orders.length === 0) {
            return this.notifications.warning(this.intl.t('driver.prompts.no-assigned-orders-warning', { driverName: driver.name }));
        }

        this.modalsManager.show('modals/assigned-orders', {
            title: this.intl.t('driver.prompts.unassign-orders-title', { driverName: driver.name }),
            acceptButtonText: this.intl.t('driver.actions.unassign-orders'),
            acceptButtonIcon: 'user-minus',
            acceptButtonDisabled: true,
            subjectLabel: this.intl.t('resource.driver'),
            subjectName: driver.name,
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
                    return this.notifications.warning(this.intl.t('driver.prompts.select-orders-warning'));
                }

                const orderList = this.orderReferenceList(selectedOrders);
                const confirmed = await this.confirmUnassignOrders({
                    title: this.intl.t('driver.prompts.confirm-unassign-orders-title', { count: selectedOrders.length, driverName: driver.name }),
                    body: this.intl.t('driver.prompts.confirm-unassign-orders-body', { driverName: driver.name, orders: orderList }),
                });

                if (!confirmed) {
                    return;
                }

                modal.startLoading();

                try {
                    await this.fetch.post(`drivers/${driver.id}/unassign-orders`, { orders: selectedOrderIds });
                    await driver.reload?.();
                    this.notifications.success(this.intl.t('driver.prompts.unassign-orders-success', { count: selectedOrders.length, driverName: driver.name }));
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

    @action unassignVehicle(driver, options = {}) {
        const vehicleName = driver.vehicle?.get?.('display_name') ?? driver.vehicle?.get?.('name') ?? driver.get?.('vehicle_name') ?? driver.vehicle_name;

        if (!driver.vehicle_uuid && !driver.vehicle?.id && !vehicleName) {
            return this.notifications.warning(this.intl.t('driver.prompts.no-vehicle-assigned-warning', { driverName: driver.name }));
        }

        this.modalsManager.confirm({
            title: this.intl.t('driver.prompts.unassign-vehicle-title', { driverName: driver.name, vehicleName }),
            body: this.intl.t('driver.prompts.unassign-vehicle-body', { driverName: driver.name, vehicleName }),
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.post(`drivers/${driver.id}/unassign-vehicle`);
                    await driver.reload?.();
                    this.notifications.success(this.intl.t('driver.prompts.unassign-vehicle-success', { driverName: driver.name, vehicleName }));
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
