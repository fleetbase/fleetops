import Route from '@ember/routing/route';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class ConnectivityEventsDetailsRoute extends Route {
    @service abilities;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service store;

    @action error(error) {
        this.notifications.serverError(error);

        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.events.index');
    }

    beforeModel() {
        if (this.abilities.cannot('fleet-ops view device-event')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.connectivity.events.index');
        }
    }

    model({ public_id }) {
        return this.store.findRecord('device-event', public_id);
    }
}
