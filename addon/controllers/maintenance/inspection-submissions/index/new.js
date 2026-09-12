import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionSubmissionsIndexNewController extends Controller {
    @service inspectionSubmissionActions;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked inspectionSubmission = this.inspectionSubmissionActions.createNewInstance();

    /** The answers, as the server accepts them. */
    @tracked answers = null;

    @task *save(inspectionSubmission) {
        try {
            yield inspectionSubmission.save();

            // The answers are posted separately, under the key the server
            // reads them from — the `inspection-submission` model declares no
            // `custom_field_values` relationship, so Ember Data drops them.
            yield this.inspectionSubmissionActions.saveAnswers(inspectionSubmission, this.answers);

            this.events.trackResourceCreated(inspectionSubmission);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', inspectionSubmission);
            this.notifications.success(this.intl.t('inspection.record.saved'));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action setAnswers(rows) {
        this.answers = rows;
    }

    @action resetForm() {
        this.answers = null;
        this.inspectionSubmission = this.inspectionSubmissionActions.createNewInstance();
    }
}
