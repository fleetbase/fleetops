import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class PartActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('part');
    }

    transition = {
        view: (part) => this.transitionTo('maintenance.parts.index.details', part),
        edit: (part) => this.transitionTo('maintenance.parts.index.edit', part),
        create: () => this.transitionTo('maintenance.parts.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const part = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'part/form',
                title: 'Create a new part',

                saveOptions: {
                    callback: this.refresh,
                },
                part,
            });
        },
        edit: (part) => {
            return this.resourceContextPanel.open({
                content: 'part/form',
                title: `Edit: ${part.name}`,

                part,
            });
        },
        view: (part) => {
            return this.resourceContextPanel.open({
                part,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'part/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const part = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: 'Create a new part',
                acceptButtonText: 'Create part',
                component: 'part/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', part, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (part, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: `Edit: ${part.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'part/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', part, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (part, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: part,
                title: part.name,
                component: 'part/details',
                ...options,
            });
        },
    };
}
