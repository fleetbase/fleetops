import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class MaintenanceActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('maintenance');
    }

    transition = {
        view: (maintenance) => this.transitionTo('maintenance.maintenances.index.details', maintenance),
        edit: (maintenance) => this.transitionTo('maintenance.maintenances.index.edit', maintenance),
        create: () => this.transitionTo('maintenance.maintenances.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const maintenance = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'maintenance/form',
                title: 'Create a new maintenance',

                saveOptions: {
                    callback: this.refresh,
                },
                maintenance,
            });
        },
        edit: (maintenance) => {
            return this.resourceContextPanel.open({
                content: 'maintenance/form',
                title: `Edit: ${maintenance.name}`,

                maintenance,
            });
        },
        view: (maintenance) => {
            return this.resourceContextPanel.open({
                maintenance,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'maintenance/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const maintenance = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: 'Create a new maintenance',
                acceptButtonText: 'Create maintenance',
                component: 'maintenance/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', maintenance, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (maintenance, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: `Edit: ${maintenance.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'maintenance/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', maintenance, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (maintenance, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: maintenance,
                title: maintenance.name,
                component: 'maintenance/details',
                ...options,
            });
        },
    };
}
