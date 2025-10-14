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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.entity')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.entity') }),
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (entity, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.isNew ? 'Create new entity' : `Edit: ${entity.name ?? entity.public_id}`,
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'entity/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', entity, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (entity, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: entity,
                title: entity.name ?? entity.public_id,
                component: 'entity/details',
                ...options,
            });
        },
    };
}
