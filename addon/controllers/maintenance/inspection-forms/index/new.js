import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionFormsIndexNewController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked inspectionForm = this.inspectionFormActions.createNewInstance();

    @task *save(inspectionForm) {
        try {
            yield inspectionForm.save();
            this.events.trackResourceCreated(inspectionForm);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', inspectionForm);
            this.notifications.success('Inspection form created.');
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.inspectionForm = this.inspectionFormActions.createNewInstance();
    }
}
