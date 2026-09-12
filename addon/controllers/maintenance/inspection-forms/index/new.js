import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionFormsIndexNewController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service events;

    @tracked overlay;
    @tracked inspectionForm = this.inspectionFormActions.createNewInstance();

    /** The builder's draft, laid out before the form record exists. */
    @tracked structure = null;

    @task *save(inspectionForm) {
        try {
            yield inspectionForm.save();

            // The structure is posted separately, under the key the server
            // reads it from — Ember Data cannot carry it, because the
            // `inspection-form` model declares no attribute for it.
            yield this.inspectionFormActions.saveStructure(inspectionForm, this.structure);

            this.events.trackResourceCreated(inspectionForm);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', inspectionForm);
            this.notifications.success(this.intl.t('inspection.form.created'));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action setStructure(groups) {
        this.structure = groups;
    }

    @action resetForm() {
        this.structure = null;
        this.inspectionForm = this.inspectionFormActions.createNewInstance();
    }
}
