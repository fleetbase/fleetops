import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsOrdersIndexNewRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;
    @service sidebar;

    // Reset the draft only when leaving the route. A refresh of this route (e.g. after saving
    // a place or customer from a modal) also transitions, and must keep what was entered.
    resetController(controller, isExiting) {
        if (isExiting) {
            controller.reset();
        }
    }

    activate() {
        this.sidebar.hide();
    }

    deactivate() {
        this.sidebar.show();
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index');
        }
    }
}
