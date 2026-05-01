import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OperationsRecurringOrdersIndexNewController extends Controller {
    @service recurringOrderScheduleActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @service events;

    @tracked overlay;
    @tracked sourceOrder = null;
    @tracked schedule = this.recurringOrderScheduleActions.createNewInstance();

    @task *save(schedule) {
        try {
            const persisted = yield this.recurringOrderScheduleActions.save(schedule);
            this.events.trackResourceCreated?.(persisted);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index.details', persisted);
            this.notifications.success(this.intl.t('common.resource-created-success', { resource: this.intl.t('resource.recurring-order-schedule') }));
            this.resetForm();
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action resetForm(sourceOrder = null) {
        this.schedule = this.recurringOrderScheduleActions.createNewInstance();
        this.sourceOrder = sourceOrder;
    }
}
