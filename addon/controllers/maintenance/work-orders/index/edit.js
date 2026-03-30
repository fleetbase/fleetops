import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class MaintenanceWorkOrdersIndexEditController extends Controller {
    @service hostRouter;
    @service intl;
    @service notifications;
    @service modalsManager;
    @service events;

    @tracked overlay;

    get actionButtons() {
        return [{ icon: 'eye', fn: this.view }];
    }

    @task *save(workOrder) {
        try {
            yield workOrder.save();
            this.events.trackResourceUpdated(workOrder);
            this.overlay?.close();
            yield this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index.details', workOrder);
            this.notifications.success(this.intl.t('common.resource-updated-success', { resource: this.intl.t('resource.work-order'), resourceName: workOrder.code }));
        } catch (err) {
            this.notifications.serverError(err);
        }
    }

    @action cancel() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index');
    }

    @action view() {
        if (this.model.hasDirtyAttributes) {
            return this.#confirmContinueWithUnsavedChanges(this.model);
        }
        return this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index.details', this.model);
    }

    #confirmContinueWithUnsavedChanges(workOrder, options = {}) {
        return this.modalsManager.confirm({
            title: this.intl.t('common.continue-without-saving'),
            body: this.intl.t('common.continue-without-saving-prompt', { resource: this.intl.t('resource.work-order') }),
            acceptButtonText: this.intl.t('common.continue'),
            confirm: async () => {
                workOrder.rollbackAttributes();
                await this.hostRouter.transitionTo('console.fleet-ops.maintenance.work-orders.index.details', workOrder);
            },
            ...options,
        });
    }
}
