import ResourceActionService, { inject as service } from '@fleetbase/ember-core/services/resource-action';

export default class EquipmentActionsService extends ResourceActionService {
    @service currentUser;

    constructor() {
        super(...arguments);
        this.initialize('equipment', {
            defaultAttributes: {
                currency: this.defaultCurrency,
            },
        });
    }

    get defaultCurrency() {
        return this.currentUser?.company?.currency || 'USD';
    }

    transition = {
        view: (equipment) => this.transitionTo('maintenance.equipment.index.details', equipment),
        edit: (equipment) => this.transitionTo('maintenance.equipment.index.edit', equipment),
        create: () => this.transitionTo('maintenance.equipment.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const equipment = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.equipment')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                equipment,
            });
        },
        edit: (equipment) => {
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: equipment.name }),
                useDefaultSaveTask: true,
                equipment,
            });
        },
        view: (equipment) => {
            return this.resourceContextPanel.open({
                equipment,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'equipment/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const equipment = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.equipment')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.equipment') }),
                component: 'equipment/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', equipment, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (equipment, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: this.intl.t('common.edit-resource-name', { resourceName: equipment.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
                saveButtonIcon: 'save',
                component: 'equipment/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', equipment, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (equipment, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: equipment.name,
                component: 'equipment/details',
                ...options,
            });
        },
    };
}
