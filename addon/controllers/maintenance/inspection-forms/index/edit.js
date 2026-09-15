import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceInspectionFormsIndexEditController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @service notifications;
    @service intl;

    @tracked overlay;

    /** The builder's draft, set only once the author has changed something. */
    @tracked structure = null;

    @task *save(inspectionForm) {
        try {
            yield inspectionForm.save();
            yield this.inspectionFormActions.saveStructure(inspectionForm, this.structure);

            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', inspectionForm);
            this.notifications.success(this.intl.t('inspection.form.updated'));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action setStructure(groups) {
        this.structure = groups;
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.details', this.model);
    }
}
