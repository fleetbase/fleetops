import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexDetailsRoute extends Route {
    @service notifications;
    @service store;
    @service hostRouter;
    @service orderSocketEvents;
    @service leafletMapManager;
    @service abilities;
    @service universe;
    @service intl;

    @action willTransition(transition) {
        const fromName = transition.from?.name;
        const toName = transition.to?.name;

        // only cleanup when actually leaving this route (not intra-route changes)
        if (fromName && fromName !== toName) {
            const controller = this.controllerFor('operations.orders.index.details');
            const rc = controller.routingControl;

            // Put back sidebar
            if (this.universe.sidebarContext) {
                this.universe.sidebarContext.show();
            }

            // stop listening for events
            this.orderSocketEvents.stop(controller.model);

            // cleanup guards
            if (rc) {
                this.leafletMapManager.removeRoutingControl(rc);
                controller.routingControl = undefined;
            }
        }

        return true;
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
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
            with: ['payload', 'driverAssigned', 'orderConfig', 'customer', 'facilitator', 'trackingStatuses', 'trackingNumber', 'purchaseRate', 'comments', 'files'],
        });
    }
}
