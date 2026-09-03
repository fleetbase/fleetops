import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionFormsIndexEditController extends Controller {
    @service hostRouter;
    @service notifications;

    @tracked overlay;

    @task *save(inspectionForm) {
        try {
            yield inspectionForm.save();
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', inspectionForm);
            this.notifications.success('Inspection form updated.');
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', this.model);
    }
}
