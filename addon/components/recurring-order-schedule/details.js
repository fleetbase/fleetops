import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class RecurringOrderScheduleDetailsComponent extends Component {
    @service recurringOrderScheduleActions;
    @service hostRouter;

    get upcomingOccurrences() {
        return this.args.resource?.upcoming_occurrences ?? this.args.resource?.meta?.upcoming_occurrences ?? [];
    }

    get activeTab() {
        return this.args.activeTab ?? 'upcoming';
    }

    get isUpcomingTab() {
        return this.activeTab === 'upcoming';
    }

    get isHistoryTab() {
        return this.activeTab === 'history';
    }

    get isSettingsTab() {
        return this.activeTab === 'settings';
    }

    get historyOccurrences() {
        return this.args.resource?.history_occurrences ?? this.args.resource?.meta?.history_occurrences ?? [];
    }

    @action selectTab(tab) {
        this.args.onSelectTab?.(tab);
    }

    @action skipOccurrence(occurrence) {
        return this.recurringOrderScheduleActions.skipOccurrence(this.args.resource, occurrence.occurrence_at).then(async () => {
            if (typeof this.args.resource?.reload === 'function') {
                await this.args.resource.reload();
            }
        });
    }

    @action viewOrder(order) {
        if (!order?.public_id) {
            return;
        }

        return this.hostRouter.transitionTo('console.fleet-ops.operations.orders.index.details', order.public_id);
    }
}
