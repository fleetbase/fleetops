import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsRecurringOrdersIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action error(error) {
        this.notifications.serverError(error);
        if (typeof error.message === 'string' && error.message.endsWith('not found')) {
            return this.hostRouter.transitionTo('operations.recurring-orders.index');
        }
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update recurring-order-schedule')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('operations.recurring-orders.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('recurring-order-schedule', public_id);
    }
}
