import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class CellRecurringSeriesBadgeComponent extends Component {
    @service recurringOrderScheduleActions;

    get order() {
        return this.args.row;
    }

    get series() {
        return this.order?.recurring_order_schedule;
    }

    get label() {
        return this.series?.name ?? this.series?.public_id ?? null;
    }

    @action openSeries(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        if (this.series) {
            return this.recurringOrderScheduleActions.transition.view(this.series);
        }
    }
}
