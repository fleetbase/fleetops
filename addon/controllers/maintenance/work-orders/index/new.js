import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceWorkOrdersIndexNewController extends Controller {
    @service workOrderActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;
    @tracked overlay;
    @tracked workOrder = this.workOrderActions.createNewInstance();

    /**
     * Holds the latest completion field values emitted by the form component
     * via its @onCompletionChange arg. No component reference is ever stored.
     */
    @tracked completionData = {};

    @action onCompletionChange(data) {
        this.completionData = data;
    }

    @task *save(workOrder) {
        try {
            this.workOrderActions.prepareForSave(workOrder, this.completionData);
            yield workOrder.save();
            this.events.trackResourceCreated(workOrder);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index.details', workOrder);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.work-order') }));
            this.resetForm();
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action resetForm() {
        this.workOrder = this.workOrderActions.createNewInstance();
        this.completionData = {};
    }
}
