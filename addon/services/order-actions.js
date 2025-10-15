import ResourceActionService, { service } from '@fleetbase/ember-core/services/resource-action';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { debug } from '@ember/debug';
import { task } from 'ember-concurrency';

export default class OrderActionsService extends ResourceActionService {
    @service store;
    modelNamePath = 'tracking';

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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.order') }),
                saveOptions: {
                    callback: this.refresh,
                },
                useDefaultSaveTask: true,
                order,
                ...options,
            });
        },
        edit: (order, options = {}) => {
            return this.resourceContextPanel.open({
                content: 'order/form',
                title: this.intl.t('common.edit-resource-name', { resourceName: order.tracking }),
                useDefaultSaveTask: true,
                order,
                ...options,
            });
        },
        view: (order, options = {}) => {
            return this.resourceContextPanel.open({
                order,
                tabs: [
                    {
                        label: this.intl.t('common.overview'),
                        component: 'order/details',
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
                title: this.intl.t('common.create-a-new-resource', { resource: this.intl.t('resource.order') }),
                acceptButtonText: this.intl.t('common.create-resource', { resource: this.intl.t('resource.order') }),
                component: 'order/form',
                confirm: (modal) => this.modalTask.perform(modal, 'saveTask', order, { refresh: true, ...saveOptions }),
                ...options,
            });
        },
        edit: (order, options = {}, saveOptions = {}) => {
            return this.modalsManager.show('modals/resource', {
                resource: order,
                title: this.intl.t('common.edit-resource-name', { resourceName: order.tracking }),
                acceptButtonText: this.intl.t('common.save-changes'),
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

    @task *saveRoute(order, saveOptions = {}) {
        const { payload } = order;

        try {
            const updatedOrder = yield this.fetch.patch(
                `orders/route/${order.id}`,
                {
                    pickup: payload.pickup,
                    dropoff: payload.dropoff,
                    return: payload.return,
                    waypoints: this.#serializeWaypoints(payload.waypoints),
                },
                {
                    normalizeToEmberData: true,
                    normalizeModelType: 'order',
                }
            );

            if (saveOptions?.closePanel === true) {
                this.resourceContextPanel.close(saveOptions.overlay.id);
            }

            return updatedOrder;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancel(order, options = {}) {
        this.modalsManager.confirm({
            title: this.intl.t('order.prompts.cancel-title'),
            body: this.intl.t('order.prompts.cancel-body'),
            acceptButtonText: this.intl.t('order.actions.cancel-order'),
            acceptButtonType: 'danger',
            acceptButtonIcon: 'ban',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/cancel', { order: order.id });
                    order.set('status', 'canceled');
                    this.notifications.success(this.intl.t('order.prompts.cancel-success', { orderId: order.tracking }));
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
            title: this.intl.t('order.prompts.dispatch-title'),
            body: this.intl.t('order.prompts.dispatch-body'),
            acceptButtonScheme: 'primary',
            acceptButtonText: this.intl.t('order.actions.dispatch-order'),
            acceptButtonIcon: 'paper-plane',
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await this.fetch.patch('orders/dispatch', { order: order.id });
                    order.set('status', 'dispatched');
                    this.notifications.success(this.intl.t('order.prompts.dispatch-success', { orderId: order.tracking }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action bulkCancel(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;

        return this.crud.bulkAction('cancel', selected, {
            acceptButtonText: this.intl.t('common.bulk-cancel-resource', { resource: this.intl.t('resource.orders') }),
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

    @action bulkDispatch(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;

        return this.crud.bulkAction('dispatch', selected, {
            acceptButtonText: this.intl.t('order.actions.dispatch-orders'),
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

    @action bulkAssignDriver(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;

        const updateFetchParams = (key, value) => {
            const current = this.modalsManager.getOption('fetchParams') ?? {};
            const next = value === undefined ? Object.fromEntries(Object.entries(current).filter(([k]) => k !== key)) : { ...current, [key]: value };

            this.modalsManager.setOption('fetchParams', next);
        };

        return this.crud.bulkAction('assign driver', selected, {
            template: 'modals/bulk-assign-driver',
            acceptButtonText: this.intl.t('order.actions.assign-driver-to-orders'),
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

    @action optimizeOrderRoutes(selected = []) {
        selected = [...(isArray(selected) ? selected : []), ...this.tableContext.getSelectedRows()];
        if (!selected) return;

        return this.hostRouter.transitionTo('console.fleet-ops.operations.routes.index.new', {
            queryParams: {
                selectedOrders: selected,
            },
        });
    }

    @action editRoute(order) {
        this.resourceContextPanel.open({
            content: 'order/route-editor',
            title: this.intl.t('order.actions.edit-order-route'),
            panelContentClass: 'py-2 px-4',
            resource: order,
            saveTask: this.saveRoute,
            saveDisabled: false,
            saveOptions: {
                closePanel: true,
            },
        });
    }

    @action updateActivity(order, options = {}) {
        return this.modalsManager.show('modals/update-order-activity', {
            title: this.intl.t('order.actions.update-order-activity'),
            order: order,
            acceptButtonText: this.intl.t('order.actions.submit-new-activity'),
            ...options,
        });
    }

    @action editOrderDetails(order, options = {}) {
        options = options === null ? {} : options;

        this.modalsManager.show('modals/order-form', {
            title: this.intl.t('order.actions.edit-order-details'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            setOrderFacilitator: (model) => {
                order.set('facilitator', model);
                order.set('facilitator_type', `fleet-ops:${model.facilitator_type}`);
                order.set('driver', null);

                if (model) {
                    this.modalsManager.setOptions('driversQuery', {
                        facilitator: model.id,
                    });
                }
            },
            setOrderCustomer: (model) => {
                order.set('customer', model);
                order.set('customer_type', `fleet-ops:${model.customer_type}`);
            },
            setDriver: (driver) => {
                order.set('driver_assigned', driver);
                if (driver && driver.vehicle) {
                    order.set('vehicle_assigned', driver.vehicle);
                }

                if (!driver) {
                    order.set('driver_assigned_uuid', null);
                }
            },
            setVehicle: (vehicle) => {
                order.set('vehicle_assigned', vehicle);
                if (!vehicle) {
                    order.set('vehicle_assigned_uuid', null);
                }
            },
            scheduleOrder: (dateInstance) => {
                order.scheduled_at = dateInstance;
            },
            driversQuery: {},
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await order.save();
                    this.notifications.success(options.successNotification || this.intl.t('fleet-ops.operations.orders.index.view.update-success', { orderId: order.public_id }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
            decline: () => {
                order.payload.rollbackAttributes();
                this.modalsManager.done();
            },
            ...options,
        });
    }

    @action async assignDriver(order) {
        if (order.canLoadDriver) {
            await order.loadDriver();
        }

        this.modalsManager.show(`modals/order-assign-driver`, {
            title: order.driver_assigned_uuid ? this.intl.t('order.actions.change-driver') : this.intl.t('order.actions.assign-driver'),
            acceptButtonText: this.intl.t('common.save-changes'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await order.save();
                    this.notifications.success(this.intl.t('order.prompts.assign-driver-success', { driverName: order.driver_assigned?.name, orderId: order.tracking }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                    modal.stopLoading();
                }
            },
        });
    }

    @action unassignDriver(order, options = {}) {
        if (!order.driver_assigned) return this.notifications.warning(this.intl.t('order.prompts.no-driver-assigned-error'));

        this.modalsManager.confirm({
            title: this.intl.t('order.prompts.unassign-driver-title', { driverName: order.driver_assigned.name }),
            body: this.intl.t('order.prompts.unassign-driver-body'),
            order,
            confirm: async (modal) => {
                modal.startLoading();

                order.setProperties({
                    driver_assigned: null,
                    driver_assigned_uuid: null,
                });

                try {
                    await order.save();
                    this.notifications.success(this.intl.t('order.prompts.unassign-driver-success'));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action viewMetadata(order, options = {}) {
        this.modalsManager.show('modals/view-metadata', {
            title: this.intl.t('order.fields.order-metadata'),
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            metadata: order.meta,
            ...options,
        });
    }

    @action editMetadata(order, options = {}) {
        this.modalsManager.show('modals/edit-metadata', {
            title: this.intl.t('order.actions.edit-order-metadata'),
            acceptButtonText: this.intl.t('common.save-changes'),
            acceptButtonIcon: 'save',
            actionsWrapperClass: 'px-3',
            metadata: order.meta,
            onChange: (meta) => {
                order.set('meta', meta);
            },
            confirm: async (modal) => {
                modal.startLoading();

                try {
                    await order.save();
                    this.notifications.success(this.intl.t('common.field-saved', { field: this.intl.t('common.metadata') }));
                    modal.done();
                } catch (error) {
                    this.notifications.serverError(error);
                } finally {
                    modal.stopLoading();
                }
            },
            ...options,
        });
    }

    @action async viewLabel(order) {
        // render dialog to display label within
        this.modalsManager.show(`modals/order-label`, {
            title: this.intl.t('order.fields.order-label'),
            modalClass: 'modal-xl',
            acceptButtonText: this.intl.t('common.done'),
            hideDeclineButton: true,
            order,
        });

        try {
            // load the pdf label from base64
            // eslint-disable-next-line no-undef
            const fileReader = new FileReader();
            const { data: pdfStream } = await this.fetch.get(`orders/label/${order.public_id}?format=base64`);
            // eslint-disable-next-line no-undef
            const base64 = await fetch(`data:application/pdf;base64,${pdfStream}`);
            const blob = await base64.blob();
            // load into file reader
            fileReader.onload = (event) => {
                const data = event.target.result;
                this.modalsManager.setOption('data', data);
            };
            fileReader.readAsDataURL(blob);
        } catch (err) {
            this.notifications.error(this.intl.t('order.prompts.failed-to-load-order-label'));
            debug('Error loading order label data: ' + err.message);
        }
    }

    #serializeWaypoints(waypoints = []) {
        if (!waypoints) return [];

        waypoints = typeof waypoints.toArray === 'function' ? waypoints.toArray() : Array.from(waypoints);
        return waypoints.map((waypoint) => {
            const json = waypoint.serialize();
            // if place is serialized just send it back
            if (json.place) return json;

            // set id for place_uuid
            json.place_uuid = waypoint.place ? waypoint.place.id : waypoint.place_uuid;
            return json;
        });
    }
}
