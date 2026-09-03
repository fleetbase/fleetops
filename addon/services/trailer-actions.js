import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class TrailerActionsService extends ResourceActionService {
    @service fetch;
    @service maintenanceScheduleActions;
    @service workOrderActions;
    @service maintenanceActions;

    constructor() {
        super(...arguments);
        this.initialize('trailer', { status: 'available', asset_class: 'trailer' });
    }

    transition = {
        view: (trailer) => this.transitionTo('management.trailers.index.details', trailer),
        edit: (trailer) => this.transitionTo('management.trailers.index.edit', trailer),
        create: () => this.transitionTo('management.trailers.index.new'),
    };

    @action attachVehicle(trailer, options = {}) {
        this.modalsManager.show('modals/attach-trailer', {
            title: this.intl.t('trailer.actions.attach-vehicle'),
            acceptButtonText: this.intl.t('trailer.actions.attach'),
            selectedVehicle: null,
            confirm: async (modal) => {
                const vehicle = modal.getOption('selectedVehicle');
                if (!vehicle) return this.notifications.warning(this.intl.t('trailer.messages.select-vehicle'));
                modal.startLoading();
                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach`, { vehicle: vehicle.id });
                    await trailer.reload();
                    this.notifications.success(this.intl.t('trailer.messages.attached'));
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

    @action async detachVehicle(trailer) {
        return this.modalsManager.confirm({
            title: this.intl.t('trailer.actions.detach-vehicle'),
            body: this.intl.t('trailer.messages.detach-confirm'),
            confirm: async (modal) => {
                try {
                    await this.fetch.post(`trailers/${trailer.id}/detach`);
                    await trailer.reload();
                    this.notifications.success(this.intl.t('trailer.messages.detached'));
                    modal.done();
                    this.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action attachDevice(trailer) {
        this.modalsManager.show('modals/attach-device', {
            title: this.intl.t('trailer.actions.attach-device'),
            acceptButtonText: this.intl.t('trailer.actions.attach'),
            selectedDevice: null,
            confirm: async (modal) => {
                const device = modal.getOption('selectedDevice');
                if (!device) return this.notifications.warning(this.intl.t('trailer.messages.select-device'));
                modal.startLoading();
                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach-device`, { device: device.id });
                    await trailer.reload();
                    this.notifications.success(this.intl.t('trailer.messages.device-attached'));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action attachEquipment(trailer) {
        this.modalsManager.show('modals/attach-equipment', {
            title: this.intl.t('trailer.actions.attach-equipment'),
            acceptButtonText: this.intl.t('trailer.actions.attach'),
            selectedEquipment: null,
            confirm: async (modal) => {
                const equipment = modal.getOption('selectedEquipment');
                if (!equipment) return this.notifications.warning(this.intl.t('trailer.messages.select-equipment'));
                modal.startLoading();
                try {
                    await this.fetch.post(`trailers/${trailer.id}/attach-equipment`, { equipment: equipment.id });
                    this.notifications.success(this.intl.t('trailer.messages.equipment-attached'));
                    modal.done();
                    this.refresh();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action async detachEquipment(trailer, equipment) {
        try {
            await this.fetch.post(`trailers/${trailer.id}/detach-equipment`, { equipment: equipment.id });
            this.notifications.success(this.intl.t('trailer.messages.equipment-detached'));
            this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action scheduleMaintenance(trailer) {
        return this.maintenanceScheduleActions.modal.create({ subject: trailer });
    }
    @action createWorkOrder(trailer) {
        return this.workOrderActions.modal.create({ target: trailer });
    }
    @action logMaintenance(trailer) {
        return this.maintenanceActions.modal.create({ maintainable: trailer });
    }
}
