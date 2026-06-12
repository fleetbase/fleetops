import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class FleetActionsService extends ResourceActionService {
    @service fetch;

    constructor() {
        super(...arguments);
        this.initialize('fleet', { defaultAttributes: { status: 'active' } });
    }

    transition = {
        view: (fleet) => this.transitionTo('management.fleets.index.details', fleet),
        edit: (fleet) => this.transitionTo('management.fleets.index.edit', fleet),
        create: () => this.transitionTo('management.fleets.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const fleet = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'fleet/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fleet')?.toLowerCase() }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                fleet,
                ...options,
            });
        },
        edit: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'fleet/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: fleet.name }),
                useDefaultSaveTask: true,
                fleet,
                ...options,
            });
        },
        view: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                fleet,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'fleet/details',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const fleet = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.fleet')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.fleet') }),
                component: 'fleet/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fleet, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (fleet, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: this.intl.t('common.edit-resource-name', { resourceName: fleet.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'fleet/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fleet, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (fleet, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: fleet.name,
                component: 'fleet/details',
                ...options,
            });
        },
    };

    @action assignDriver(fleet, options = {}) {
        this.modalsManager.show('modals/fleet-assign-driver', {
            title: this.intl.t('fleet.prompts.assign-driver-title', { fleetName: fleet.name }),
            acceptButtonText: this.intl.t('fleet.actions.assign-driver'),
            acceptButtonIcon: 'user-plus',
            selectedDriver: null,
            fleet,
            confirm: async (modal) => {
                const selectedDriver = modal.getOption('selectedDriver');

                if (!selectedDriver) {
                    return this.notifications.warning(this.intl.t('fleet.prompts.select-driver-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post('fleets/assign-driver', { driver: selectedDriver.id, fleet: fleet.id });
                    await fleet.reload?.();
                    this.notifications.success(this.intl.t('fleet.prompts.assign-driver-success', { fleetName: fleet.name }));
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

    @action assignVehicle(fleet, options = {}) {
        this.modalsManager.show('modals/fleet-assign-vehicle', {
            title: this.intl.t('fleet.prompts.assign-vehicle-title', { fleetName: fleet.name }),
            acceptButtonText: this.intl.t('fleet.actions.assign-vehicle'),
            acceptButtonIcon: 'car',
            selectedVehicle: null,
            fleet,
            confirm: async (modal) => {
                const selectedVehicle = modal.getOption('selectedVehicle');

                if (!selectedVehicle) {
                    return this.notifications.warning(this.intl.t('fleet.prompts.select-vehicle-warning'));
                }

                modal.startLoading();

                try {
                    await this.fetch.post('fleets/assign-vehicle', { vehicle: selectedVehicle.id, fleet: fleet.id });
                    await fleet.reload?.();
                    this.notifications.success(this.intl.t('fleet.prompts.assign-vehicle-success', { fleetName: fleet.name }));
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
