import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class RecurringOrderScheduleDetailsComponent extends Component {
    @service recurringOrderScheduleActions;
    @service hostRouter;

    get upcomingOccurrences() {
        return this.args.resource?.upcoming_occurrences ?? this.args.resource?.meta?.upcoming_occurrences ?? [];
    }

    @action skipOccurrence(occurrence) {
        return this.recurringOrderScheduleActions.skipOccurrence(this.args.resource, occurrence.occurrence_at);
    }

    @action viewOrder(order) {
        if (!order?.public_id) {
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', order.public_id);
    }
}
