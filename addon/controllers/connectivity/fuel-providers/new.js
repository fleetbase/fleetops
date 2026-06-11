import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityFuelProvidersIndexNewController extends Controller {
    @service fuelIntegrationActions;
    @service hostRouter;
    @service notifications;
    @service events;

    queryParams = ['setupProvider'];
    @tracked setupProvider;
    @tracked connection = this.fuelIntegrationActions.createNewInstance();

    @task *save(connection) {
        try {
            yield connection.save();
            this.events.trackResourceCreated(connection);
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.details', connection);
            this.notifications.success('Fuel integration connected.');
            this.resetForm();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action resetForm() {
        this.connection = this.fuelIntegrationActions.createNewInstance();
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.connectivity.fuel-providers.index');
    }
}
