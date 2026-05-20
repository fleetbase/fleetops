import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetOnTimeDeliveryComponent extends Component {
    static widgetId = 'fleet-ops-on-time-delivery-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get gaugeColor() {
        const pct = this.data?.on_time_pct ?? 0;
        if (pct >= 95) return '#22c55e';
        if (pct >= 85) return '#84cc16';
        if (pct >= 70) return '#f59e0b';
        return '#ef4444';
    }

    get chartLabels() {
        return ['On Time', 'Late'];
    }

    get chartDatasets() {
        return [
            {
                data: [this.data?.on_time ?? 0, this.data?.late ?? 0],
                backgroundColor: [this.gaugeColor, 'rgba(148,163,184,0.25)'],
                borderWidth: 0,
            },
        ];
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '75%',
            plugins: {
                legend: { display: false },
                tooltip: { enabled: true },
            },
        };
    }

    get deltaStatus() {
        const d = this.data?.delta_pct;
        if (typeof d !== 'number' || d === 0) return null;
        return d > 0 ? 'success' : 'danger';
    }

    get deltaText() {
        const d = this.data?.delta_pct;
        if (typeof d !== 'number') return null;
        return `${d > 0 ? '+' : ''}${d} pp`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/on-time-delivery', { period: '30d' });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load on-time delivery';
        }
    }
}
