import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexDetailsRoute extends Route {
    @service notifications;
    @service store;
    @service hostRouter;
    @service orderSocketEvents;
    @service mapManager;
    @service abilities;
    @service intl;
    @service sidebar;

    @action willTransition(transition) {
        const fromName = transition.from?.name;
        const toName = transition.to?.name;
        const orderDetailsRoute = 'console.fleet-ops.operations.orders.index.details';
        const isRefreshWithinSameDetailsRoute = fromName?.startsWith(orderDetailsRoute) && toName?.startsWith(orderDetailsRoute) && fromName === toName;

        if (isRefreshWithinSameDetailsRoute) {
            return true;
        }

        // only cleanup when leaving the order details route tree entirely
        const isLeavingOrderDetails = fromName?.startsWith(orderDetailsRoute) && !toName?.startsWith(orderDetailsRoute);
        if (isLeavingOrderDetails) {
            const controller = this.controllerFor('operations.orders.index.details');
            const rc = controller.routingControl;

            // stop listening for events
            this.orderSocketEvents.stop(controller.model);

            // cleanup guards
            if (rc) {
                this.mapManager.removeRoutingControl(rc);
                controller.routingControl = undefined;
            }
        }

        return true;
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
    }

    activate() {
        this.sidebar.hide();
    }

    deactivate() {
        this.sidebar.show();
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }
    }

    model({ public_id }) {
        return this.store.queryRecord('order', {
            public_id,
            single: true,
            with: [
                'payload',
                'driverAssigned',
                'orderConfig',
                'customer',
                'facilitator',
                'trackingStatuses',
                'trackingNumber',
                'purchaseRate',
                'purchaseRate.serviceQuote',
                'purchaseRate.serviceQuote.items',
                'comments',
                'files',
            ],
        });
    }

    async afterModel(order) {
        await order.loadTrackingActivity();
        if (order.meta?._index_resource) {
            await order.reload();
        }
    }
}
