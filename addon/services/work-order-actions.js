import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class WorkOrderActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('work-order');
    }

    transition = {
        view: (workOrder) => this.transitionTo('maintenance.work-orders.index.details', workOrder),
        edit: (workOrder) => this.transitionTo('maintenance.work-orders.index.edit', workOrder),
        create: () => this.transitionTo('maintenance.work-orders.index.new'),
    };

    panel = {
        create: (attributes = {}) => {
            const workOrder = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'work-order/form',
                title: 'Create a new work order',

                saveOptions: {
                    callback: this.refresh,
                },
                workOrder,
            });
        },
        edit: (workOrder) => {
            return this.resourceContextPanel.open({
                content: 'work-order/form',
                title: `Edit: ${workOrder.name}`,

                workOrder,
            });
        },
        view: (workOrder) => {
            return this.resourceContextPanel.open({
                workOrder,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'work-order/details',
                    },
                ],
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const workOrder = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: 'Create a new work order',
                acceptButtonText: 'Create workOrder',
                component: 'work-order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', workOrder, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (workOrder, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: `Edit: ${workOrder.name}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'work-order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', workOrder, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (workOrder, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: workOrder.name,
                component: 'work-order/details',
                ...options,
            });
        },
    };
}
