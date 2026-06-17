import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MaintenanceInspectionSubmissionsIndexDetailsController extends Controller {
    @service inspectionSubmissionActions;
    @service hostRouter;
    @tracked overlay;

    get actionButtons() {
        return [
            { icon: 'triangle-exclamation', fn: this.createIssue, text: 'Create Issue', permission: 'fleet-ops create-issue inspection-submission' },
            { icon: 'clipboard-list', fn: this.createWorkOrder, text: 'Create Work Order', permission: 'fleet-ops create-work-order inspection-submission' },
            { icon: 'check', fn: this.resolve, text: 'Resolve', permission: 'fleet-ops resolve inspection-submission' },
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update inspection-submission' },
            { icon: 'trash', fn: this.delete, type: 'danger', permission: 'fleet-ops delete inspection-submission' },
        ];
    }

    @action createIssue() {
        return this.inspectionSubmissionActions.createIssue(this.model);
    }

    @action createWorkOrder() {
        return this.inspectionSubmissionActions.createWorkOrder(this.model);
    }

    @action resolve() {
        return this.inspectionSubmissionActions.resolve(this.model);
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index.edit', this.model);
    }

    @action delete() {
        return this.inspectionSubmissionActions.delete(this.model, {
            onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-submissions.index'),
        });
    }
}
