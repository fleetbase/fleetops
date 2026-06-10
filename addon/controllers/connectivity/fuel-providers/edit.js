import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexEditController extends Controller {
    @service hostRouter;
    @service notifications;

    @task *save(connection) {
        try {
            yield connection.save();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.details', connection);
            this.notifications.success('Fuel integration settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.details', this.model);
    }
}
