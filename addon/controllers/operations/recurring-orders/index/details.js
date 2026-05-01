import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsRecurringOrdersIndexDetailsController extends Controller {
    @service recurringOrderScheduleActions;
    @service hostRouter;
    @tracked overlay;

    get actionButtons() {
        return [
            {
                items: [
                    { text: 'Edit schedule', icon: 'pencil', fn: this.edit },
                    { text: 'Pause schedule', icon: 'pause', fn: () => this.recurringOrderScheduleActions.pause(this.model) },
                    { text: 'Resume schedule', icon: 'play', fn: () => this.recurringOrderScheduleActions.resume(this.model) },
                    { separator: true },
                    { text: 'Cancel future orders', icon: 'ban', class: 'text-danger', fn: this.cancelFuture },
                    { text: 'Delete schedule', icon: 'trash', class: 'text-danger', fn: this.delete },
                ],
            },
        ];
    }

    @action edit() {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index.edit', this.model);
    }

    @action cancelFuture() {
        return this.recurringOrderScheduleActions.cancelFuture(this.model, { cancelGeneratedOrders: false });
    }

    @action delete() {
        return this.recurringOrderScheduleActions.delete(this.model, {
            onConfirm: () => this.hostRouter.transitionTo('console.fleet-ops.operations.recurring-orders.index'),
        });
    }
}
