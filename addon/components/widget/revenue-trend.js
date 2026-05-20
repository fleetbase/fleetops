import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

const PERIODS = ['7d', '30d', '90d'];

export default class WidgetRevenueTrendComponent extends Component {
    static widgetId = 'fleet-ops-revenue-trend-widget';

    @service fetch;

    @tracked period = '30d';
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
                legend: { display: false },
                tooltip: { mode: 'index', intersect: false },
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } },
            },
            elements: { point: { radius: 0, hoverRadius: 4 } },
        };
    }

    get deltaStatus() {
        const pct = this.data?.summary?.delta_pct;
        if (typeof pct !== 'number' || pct === 0) return null;
        return pct > 0 ? 'success' : 'danger';
    }

    get deltaText() {
        const pct = this.data?.summary?.delta_pct;
        if (typeof pct !== 'number') return null;
        return `${pct > 0 ? '+' : ''}${pct}%`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/revenue-trend', { period: this.period });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load revenue trend';
        }
    }

    @action
    setPeriod(period) {
        this.period = period;
        this.load.perform();
    }
}
