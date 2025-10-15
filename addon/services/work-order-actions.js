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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.work order')?.toLowerCase() }),
                useDefaultSaveTask: true,
                saveOptions: {
                    callback: this.refresh,
                },
                workOrder,
            });
        },
        edit: (workOrder) => {
            return this.resourceContextPanel.open({
                content: 'work-order/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: workOrder.name }),
                useDefaultSaveTask: true,
                workOrder,
            });
        },
        view: (workOrder) => {
            return this.resourceContextPanel.open({
                workOrder,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.work order')?.toLowerCase() }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.work-order') }),
                component: 'work-order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', workOrder, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (workOrder, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: workOrder,
                title: this.intl.t('common.edit-resource-name', { resourceName: workOrder.name }),
                acceptButtonText: this.intl.t('common.save-changes'),
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
