import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MaintenanceWorkOrdersIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    @action error(error) {
        this.notifications.serverError(error);
        if (typeof error.message === 'string' && error.message.endsWith('not found')) {
            return this.hostRouter.transitionTo('maintenance.work-orders.index');
        }
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view work-order')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('maintenance.work-orders.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('work-order', public_id);
    }
}
