import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ConnectivityTelematicsIndexNewController extends Controller {
    @service telematicActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;
    @tracked telematic = this.telematicActions.createNewInstance();

    @task *save(telematic) {
        try {
            yield telematic.save();
            this.overlay?.close();

            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.connectivity.telematics.index.details', telematic);
            this.notifications.success('Telematic created successfully.');
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.telematic = this.telematicActions.createNewInstance();
    }
}
