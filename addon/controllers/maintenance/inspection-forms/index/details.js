import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MaintenanceInspectionFormsIndexDetailsController extends Controller {
    @service inspectionFormActions;
    @service hostRouter;
    @tracked overlay;

    /**
     * Publish disappears once the form is published — leaving it there invites
     * an author to press a button that can only tell them it is already done.
     * The public link needs a published form, so it appears at the same moment.
     */
    get actionButtons() {
        const isPublished = this.model?.is_published === true || this.model?.status === 'published';

        return [
            ...(isPublished ? [] : [{ icon: 'check', fn: this.publish, text: 'Publish', type: 'success', permission: 'fleet-ops publish inspection-form' }]),
            ...(isPublished ? [{ icon: 'link', fn: this.generateLink, text: 'Generate Link', permission: 'fleet-ops view inspection-form' }] : []),
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
