import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed } from '@ember/object';
import moment from 'moment';

export default class WidgetKeyMetricsComponent extends Component {
    @service fetch;
    @tracked metrics = {
        distance_traveled: 0,
        total_time_traveled: 0,
        drivers_online: 0,
        customers: 0,
        earnings: 0,
        fuel_cost: 0,
        orders_canceled: 0,
        orders_completed: 0,
        orders_in_progress: 0,
        orders_scheduled: 0,
        open_issues: 0,
        resolved_issues: 0,
    };
    @tracked isLoading = true;

    @computed('args.title') get title() {
        return this.args.title || 'This Month';
    }

    @action async getMetrics() {
        const start = moment().startOf('month').toString();
        const end = moment().endOf('month').toString();

        this.metrics = await this.fetchMetrics(start, end);
    }

    fetchMetrics(start, end) {
        this.isLoading = true;

        return new Promise((resolve) => {
            this.fetch.get('actions/get-fleet-ops-key-metrics', { start, end }).then((metrics) => {
                this.isLoading = false;
                resolve(metrics);
            });
        });
    }
}
