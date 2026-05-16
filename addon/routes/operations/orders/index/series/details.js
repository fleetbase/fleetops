import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexSeriesDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service sidebar;

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.series');
    }

    activate() {
        this.sidebar.hide();
    }

    deactivate() {
        this.sidebar.show();
    }

    model({ public_id }) {
        return this.store.queryRecord('recurring-order-schedule', {
            public_id,
            single: true,
            with: ['customer', 'facilitator', 'orderConfig', 'serviceRate', 'driverAssigned', 'vehicleAssigned'],
            upcoming_limit: 25,
        });
    }
}
