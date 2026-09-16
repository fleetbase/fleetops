import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class MaintenanceInspectionFormsIndexDetailsController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @service intl;
    @tracked overlay;

    get tabs() {
        return [
            { id: 'index', route: 'maintenance.inspection-forms.index.details.index', label: this.intl.t('inspection.form.overview') },
            { id: 'submissions', route: 'maintenance.inspection-forms.index.details.submissions', label: this.intl.t('inspection.form.submissions') },
        ];
    }

    /**
     * Publish disappears once the form is published — leaving it there invites
     * an author to press a button that can only tell them it is already done.
     * The public link needs a published form, so it appears at the same moment.
     */
    get actionButtons() {
        return this.inspectionFormActions.headerActionButtons(this.model, {
            onEdit: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.edit', this.model),
            onDeleted: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index'),
        });
    }
}
