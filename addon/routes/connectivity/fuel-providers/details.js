import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ConnectivityFuelProvidersIndexDetailsRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.activity = null;
        controller.activityError = null;
        controller.watchActivity.perform();
    }

    deactivate() {
        this.controller.watchActivity.cancelAll();
    }

    @action error(error) {
        this.notifications.serverError(error);
        if (error?.errors?.some((entry) => entry.status === '404')) return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
        return false;
    }

    model({ public_id }) {
        // URLs use public IDs; the store's primary key is the API UUID.
        return this.store.queryRecord('fuel-provider-connection', { public_id, single: true });
    }
}
