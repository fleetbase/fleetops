import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionSubmissionsIndexEditController extends Controller {
    @service hostRouter;
    @service notifications;

    @tracked overlay;

    @task *save(inspectionSubmission) {
        try {
            yield inspectionSubmission.save();
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', inspectionSubmission);
            this.notifications.success('Inspection updated.');
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', this.model);
    }
}
