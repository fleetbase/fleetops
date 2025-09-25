import ResourceActionService from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';

export default class OrderActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('order', {
            defaultAttributes: {
                meta: {},
                payload: this.store.createRecord('payload'),
            },
        });
    }

    transition = {
        view: (order) => this.transitionTo('operations.orders.index.details', order),
        edit: (order) => this.transitionTo('operations.orders.index.edit', order),
        create: () => this.transitionTo('operations.orders.index.new'),
    };

    panel = {
        create: (attributes = {}, options = {}) => {
            const order = this.createNewInstance(attributes);
            return this.resourceContextPanel.open({
                content: 'order/form',
                title: 'Create a new order',
                panelContentClass: 'px-4',
                saveOptions: {
                    callback: this.refresh,
                },
                order,
                ...options,
            });
        },
        edit: (order, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'order/form',
                title: `Edit: ${order.tracking}`,
                panelContentClass: 'px-4',
                order,
                ...options,
            });
        },
        view: (order, options = {}) => {
            return this.resourceContextPanel.open({
                order,
                tabs: [
                    {
                        label: 'Overview',
                        component: 'order/details',
                        contentClass: 'p-4',
                    },
                ],
                ...options,
            });
        },
    };

    modal = {
        create: (attributes = {}, options = {}, saveOptions = {}) => {
            const order = this.createNewInstance(attributes);
            return this.modalsManager.show('modals/resource', {
                resource: order,
                title: 'Create a new order',
                acceptButtonText: 'Create Order',
                component: 'order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', order, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (order, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: order,
                title: `Edit: ${order.tracking}`,
                acceptButtonText: 'Save Changes',
                saveButtonIcon: 'save',
                component: 'order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', order, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        view: (order, options = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: order,
                title: order.tracking,
                component: 'order/details',
                ...options,
            });
        },
    };

    @action cancel(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.cancel-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.cancel-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id });
                    order.set('status', 'canceled');
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.cancel-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action dispatch(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('fleet-ops.operations.orders.index.dispatch-title'),
            body: this.intl.t('fleet-ops.operations.orders.index.dispatch-body'),
            acceptButtonScheme: 'primary',
            acceptButtonText: 'Dispatch',
            acceptButtonIcon: 'paper-plane',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/dispatch', { order: order.id });
                    order.set('status', 'dispatched');
                    this.notifications.success(this.intl.t('fleet-ops.operations.orders.index.dispatch-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action bulkCancel() {
        const selected = this.tableContext.getSelectedRows();
        if (!selected) return;

        return this.crud.bulkAction('cancel', selected, {
            acceptButtonText: 'Cancel Orders',
            acceptButtonScheme: 'danger',
            acceptButtonIcon: 'ban',
            actionPath: 'orders/bulk-cancel',
            actionMethod: 'PATCH',
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            withSelected: (orders) => {
                orders.forEach((order) => {
                    order.set('status', 'canceled');
                });
            },
            onSuccess: async () => {
                this.tableContext.untoggleSelectAll();
                await this.hostRouter.refresh();
            },
        });
    }

    @action bulkDispatch() {
        const selected = this.tableContext.getSelectedRows();
        if (!selected) return;

        return this.crud.bulkAction('dispatch', selected, {
            acceptButtonText: 'Dispatch Orders',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'rocket',
            actionPath: 'orders/bulk-dispatch',
            actionMethod: 'POST',
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            withSelected: (orders) => {
                orders.forEach((order) => {
                    order.set('status', 'dispatched');
                });
            },
            onSuccess: async () => {
                this.tableContext.untoggleSelectAll();
                await this.hostRouter.refresh();
            },
        });
    }

    @action bulkAssignDriver() {
        const selected = this.tableContext.getSelectedRows();
        if (!selected) return;

        const updateFetchParams = (key, value) => {
            const current = this.modalsManager.getOption('fetchParams') ?? {};
            const next = value === undefined ? Object.fromEntries(Object.entries(current).filter(([k]) => k !== key)) : { ...current, [key]: value };

            this.modalsManager.setOption('fetchParams', next);
        };

        return this.crud.bulkAction('assign driver', selected, {
            template: 'modals/bulk-assign-driver',
            acceptButtonText: 'Assign Driver to Orders',
            acceptButtonScheme: 'magic',
            acceptButtonIcon: 'user-plus',
            acceptButtonDisabled: true,
            actionPath: 'orders/bulk-assign-driver',
            actionMethod: 'PATCH',
            driverAssigned: null,
            notifyDriver: true,
            fetchParams: {},
            resolveModelName: (model) => `${model.get('tracking_number.tracking_number')} - ${model.get('public_id')}`,
            selectDriver: (driver) => {
                this.modalsManager.setOptions({
                    driverAssigned: driver,
                    acceptButtonDisabled: driver ? false : true,
                });

                updateFetchParams('driver', driver?.id);
            },
            toggleNotifyDriver: (checked) => {
                this.modalsManager.setOption('notifyDriver', checked);
                updateFetchParams('silent', !checked);
            },
            withSelected: (orders) => {
                const driverAssigned = this.modalsManager.getOption('driverAssigned');
                orders.forEach((order) => {
                    order.set('driver_assigned', driverAssigned);
                });
            },
            onSuccess: async () => {
                this.tableContext.untoggleSelectAll();
                await this.hostRouter.refresh();
            },
        });
    }
}
