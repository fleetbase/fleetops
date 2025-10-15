import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityDevicesIndexNewController extends Controller {
    @service deviceActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked device = this.deviceActions.createNewInstance();

    @task *save(device) {
        try {
            yield device.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.devices.index.details', device);
            this.notifications.success('Device created successfully.');
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.device = this.deviceActions.createNewInstance();
    }
}
