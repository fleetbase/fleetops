import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionSubmissionsIndexNewController extends Controller {
    @service inspectionSubmissionActions;
    @service hostRouter;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked inspectionSubmission = this.inspectionSubmissionActions.createNewInstance();

    @task *save(inspectionSubmission) {
        try {
            yield inspectionSubmission.save();
            this.events.trackResourceCreated(inspectionSubmission);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', inspectionSubmission);
            this.notifications.success('Inspection saved.');
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.inspectionSubmission = this.inspectionSubmissionActions.createNewInstance();
    }
}
