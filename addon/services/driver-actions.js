import Service, { inject as service } from '@ember/service';
import leafletIcon from '@fleetbase/ember-core/utils/leaflet-icon';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';
import config from '../config/environment';

export default class DriverActionsService extends Service {
    @service modalsManager;
    @service universe;
    @service crud;

    assignOrder(driver, options = {}) {
        this.modalsManager.show('modals/driver-assign-order', {
            title: `Assign Order to this Driver`,
            acceptButtonText: 'Assign Order',
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
            confirm: (modal) => {
                const selectedOrder = modal.getOption('selectedOrder');
                if (!selectedOrder) {
                    this.notifications.warning('No order selected!');
                    return;
                }

                modal.startLoading();
                driver.set('current_job_uuid', selectedOrder.id);

                return driver
                    .save()
                    .then(() => {
                        this.notifications.success(`${driver.name} assigned to order.`);
                    })
                    .catch((error) => {
                        driver.rollbackAttributes();
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    assignVehicle(driver, options = {}) {
        this.modalsManager.show('modals/driver-assign-vehicle', {
            title: `Assign Vehicle to this Driver`,
            acceptButtonText: 'Confirm & Create',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            hideDeclineButton: true,
            driver,
            confirm: (modal) => {
                modal.startLoading();

                return driver
                    .save()
                    .then((driver) => {
                        this.notifications.success(`${driver.name} assigned to vehicle.`);
                    })
                    .catch((error) => {
                        driver.rollbackAttributes();
                        modal.stopLoading();
                        this.notifications.serverError(error);
                    });
            },
            ...options,
        });
    }

    locate(driver, options = {}) {
        const { location } = driver;
        const [latitude, longitude] = location.coordinates;

        this.modalsManager.show('modals/point-map', {
            title: `Current Location of ${driver.name}`,
            acceptButtonText: 'Done',
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            modalClass: 'modal-md',
            hideDeclineButton: true,
            latitude,
            longitude,
            location,
            popupText: `${driver.name} (${driver.public_id})`,
            icon: leafletIcon({
                iconUrl: getWithDefault(driver, 'vehicle_avatar', getWithDefault(config, 'defaultValues.vehicleAvatar')),
                iconSize: [40, 40],
            }),
            ...options,
        });
    }

    delete(driver, options = {}) {
        this.crud.delete(driver, {
            onSuccess: () => {
                this.universe.trigger('fleet-ops.driver.deleted', driver);
            },
            ...options,
        });
    }
}
