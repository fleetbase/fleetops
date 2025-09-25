import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class FleetActionsService extends ResourceActionService {
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
                title: 'Create a new fleet',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                fleet,
                ...options,
            });
        },
        edit: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'fleet/form',
                title: `Edit: ${fleet.name}`,
                panelContentClass: 'px-4',
                fleet,
                ...options,
            });
        },
        view: (fleet, options = {}) => {
            return this.resourceContextPanel.open({
                fleet,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'fleet/details',
                        contentClass: 'p-4',
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
                title: 'Create a new fleet',
                acceptButtonText: 'Create Fleet',
                component: 'fleet/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', fleet, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (fleet, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: fleet,
                title: `Edit: ${fleet.name}`,
                acceptButtonText: 'Save Changes',
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
}
