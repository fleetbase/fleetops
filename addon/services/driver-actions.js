import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import config from 'ember-get-config';
import { action } from '@ember/object';

export default class DriverActionsService extends ResourceActionService {
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
        edit: (driver, options = {}) => {
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
        view: (driver, options = {}) => {
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
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'driver/details',
                    },
                ],
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
        edit: (driver, options = {}, saveOptions = {}) => {
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
        view: (driver, options = {}) => {
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
            title: this.intl.t('fleet-ops.management.drivers.index.order-driver'),
            acceptButtonText: this.intl.t('fleet-ops.management.drivers.index.assign-order'),
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
                    return this.notifications.warning(this.intl.t('fleet-ops.management.drivers.index.no-order-warning'));
                }

                modal.startLoading();

                try {
                    driver.set('current_job_uuid', selectedOrder.id);
                    await driver.save();
                } catch (err) {
                    this.notifications.serverError(err);
                    driver.rollbackAttributes();
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action assignVehicle(driver, options = {}) {
        this.modalsManager.show('modals/driver-assign-vehicle', {
            title: this.intl.t('fleet-ops.management.drivers.index.title-vehicle'),
            acceptButtonText: this.intl.t('fleet-ops.management.drivers.index.confirm-button'),
            acceptButtonIcon: 'check',
            hideDeclineButton: true,
            driver,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await driver.save();
                    this.notifications.success(this.intl.t('fleet-ops.management.drivers.index.assign-vehicle', { driverName: driver.name }));
                } catch (err) {
                    this.notifications.serverError(err);
                    driver.rollbackAttributes();
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }
}
