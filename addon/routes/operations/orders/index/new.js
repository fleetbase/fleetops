import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsOrdersIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action willTransition() {
        if (this.controller) {
            this.controller.reset();
        }
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }
    }
}
