import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class EquipmentActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('equipment');
    }

    transition = {
        view: (equipment) => this.transitionTo('maintenance.equipments.index.details', equipment),
        edit: (equipment) => this.transitionTo('maintenance.equipments.index.edit', equipment),
        create: () => this.transitionTo('maintenance.equipments.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const equipment = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: 'Create a new equipment',

                saveOptions: {
                    callback: this.refresh,
                },
                equipment,
            });
        },
        edit: (equipment) => {
            return this.resourceContextPanel.open({
                content: 'equipment/form',
                title: `Edit: ${equipment.name}`,

                equipment,
            });
        },
        view: (equipment) => {
            return this.resourceContextPanel.open({
                equipment,
                tabs: [
                    {
                        label: 'Overview',
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
                title: 'Create a new equipment',
                acceptButtonText: 'Create Equipment',
                component: 'equipment/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', equipment, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (equipment, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: equipment,
                title: `Edit: ${equipment.name}`,
                acceptButtonText: 'Save Changes',
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
