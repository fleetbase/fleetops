import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class EntityActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('entity', { defaultAttributes: {} });
    }

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const entity = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: 'Create a new entity',
                acceptButtonText: 'Create Entity',
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (entity, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.isNew ? 'Create new entity' : `Edit: ${entity.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (entity, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.name,
                component: 'entity/details',
                ...options,
            });
        },
    };
}
