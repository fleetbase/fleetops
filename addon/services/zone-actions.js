import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class ZoneActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('zone');
    }

    transition = {
        view: (zone) => this.transitionTo('operations.orders.index', { queryParams: { zone: zone.id } }),
        edit: (zone) => this.transitionTo('operations.orders.index', { queryParams: { zone: zone.id, editing: zone.id } }),
        create: () => this.transitionTo('operations.orders.index', { queryParams: { creating: 'zone' } }),
    };

    panel = {
        create: (attributes = {}) => {
            const zone = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'zone/form',
                title: 'Create a new zone',

                saveOptions: {
                    callback: this.refresh,
                },
                zone,
            });
        },
        edit: (zone) => {
            return this.resourceContextPanel.open({
                content: 'zone/form',
                title: `Edit: ${zone.displayName}`,

                zone,
            });
        },
        view: (zone) => {
            return this.resourceContextPanel.open({
                zone,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'zone/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const zone = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: 'Create a new zone',
                acceptButtonText: 'Create zone',
                component: 'zone/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', zone, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (zone, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: `Edit: ${zone.displayName}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'zone/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', zone, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (zone, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: zone,
                title: zone.displayName,
                component: 'zone/details',
                ...options,
            });
        },
    };
}
