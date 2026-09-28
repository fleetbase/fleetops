import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConnectivityFuelProvidersIndexEditRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops update fuel-provider-connection')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
        }
    }

    @action error(error) {
        this.notifications.serverError(error);
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
    }

    model({ public_id }) {
        // URLs use public IDs; the store's primary key is the API UUID.
        return this.store.queryRecord('fuel-provider-connection', { public_id, single: true });
    }
}
