import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivitySensorsIndexNewController extends Controller {
    @service sensorActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked sensor = this.sensorActions.createNewInstance();

    @task *save(sensor) {
        try {
            yield sensor.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.sensors.index.details', sensor);
            this.notifications.success('Sensor created successfully.');
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.sensor = this.sensorActions.createNewInstance();
    }
}
