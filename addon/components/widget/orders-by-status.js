import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

const PERIODS = ['7d', '14d', '30d'];

export default class WidgetOrdersByStatusComponent extends Component {
    static widgetId = 'fleet-ops-orders-by-status-widget';

    @service fetch;

    @tracked period = '14d';
    @tracked data = null;
    @tracked error = null;

    periods = PERIODS;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
            },
        };
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/orders-by-status', { period: this.period });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load orders breakdown';
        }
    }

    @action
    setPeriod(period) {
        this.period = period;
        this.load.perform();
    }
}
