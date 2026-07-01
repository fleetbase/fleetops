import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MaintenanceInspectionFormsIndexDetailsController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @tracked overlay;

    get actionButtons() {
        return [
            { icon: 'check', fn: this.publish, text: 'Publish', permission: 'fleet-ops publish inspection-form' },
            { icon: 'link', fn: this.generateLink, text: 'Generate Link', permission: 'fleet-ops view inspection-form' },
            { icon: 'edit', fn: this.edit, permission: 'fleet-ops update inspection-form' },
            { icon: 'trash', fn: this.delete, type: 'danger', permission: 'fleet-ops delete inspection-form' },
        ];
    }

    @action publish() {
        return this.inspectionFormActions.publish(this.model);
    }

    @action generateLink() {
        return this.inspectionFormActions.generateLink(this.model);
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index.edit', this.model);
    }

    @action delete() {
        return this.inspectionFormActions.delete(this.model, {
            onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.maintenance.inspection-forms.index'),
        });
    }
}
