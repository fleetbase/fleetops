import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConnectivityFuelProvidersIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
    }

    model({ public_id }) {
        return this.store.findRecord('fuel-provider-connection', public_id);
    }
}
