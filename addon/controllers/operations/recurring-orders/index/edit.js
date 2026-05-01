import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class OperationsRecurringOrdersIndexEditController extends Controller {
    @service recurringOrderScheduleActions;
    @service hostRouter;
    @service intl;
    @service notifications;
    @tracked overlay;

    get actionButtons() {
        return [{ icon: 'trash', fn: this.delete, permission: 'fleet-ops delete recurring-order-schedule' }];
    }

    @task *save(schedule) {
        try {
            const persisted = yield this.recurringOrderScheduleActions.save(schedule);
            this.overlay?.close();
            yield this.hostRouter.refresh();
            yield this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index.details', persisted);
            this.notifications.success(this.intl.t('common.resource-updated-success', { resource: this.intl.t('resource.recurring-order-schedule') }));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action cancel() {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index');
    }

    @action delete() {
        return this.recurringOrderScheduleActions.delete(this.model, {
            onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index'),
        });
    }
}
