import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetFuelEfficiencyComponent extends Component {
    static widgetId = 'fleet-ops-fuel-efficiency-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } },
            },
            scales: {
                x: { grid: { display: false } },
                y1: { position: 'left', beginAtZero: true, title: { display: false } },
                y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false } },
            },
        };
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/fuel-efficiency', { period: '90d' });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load fuel data';
        }
    }
}
