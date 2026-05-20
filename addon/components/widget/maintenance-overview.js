import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetMaintenanceOverviewComponent extends Component {
    static widgetId = 'fleet-ops-maintenance-overview-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    priorityStatus(priority) {
        switch (priority) {
            case 'high':
            case 'urgent':
                return 'danger';
            case 'medium':
            case 'normal':
                return 'warning';
            default:
                return 'info';
        }
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/maintenance-overview');
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load maintenance overview';
        }
    }
}
