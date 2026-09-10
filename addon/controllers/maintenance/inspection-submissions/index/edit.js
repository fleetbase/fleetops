import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionSubmissionsIndexEditController extends Controller {
    @service inspectionSubmissionActions;
    @service hostRouter;
    @service notifications;
    @service intl;

    @tracked overlay;

    /** The answers, as the server accepts them. */
    @tracked answers = null;

    @task *save(inspectionSubmission) {
        try {
            yield inspectionSubmission.save();
            yield this.inspectionSubmissionActions.saveAnswers(inspectionSubmission, this.answers);

            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', inspectionSubmission);
            this.notifications.success(this.intl.t('inspection.record.updated'));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action setAnswers(rows) {
        this.answers = rows;
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.details', this.model);
    }
}
