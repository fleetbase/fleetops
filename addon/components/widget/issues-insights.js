import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

const CATEGORY_PALETTE = ['#3485e2', '#f59e0b', '#22c55e', '#ef4444', '#8b5cf6', '#a3a3a3', '#0ea5e9', '#ec4899'];

export default class WidgetIssuesInsightsComponent extends Component {
    static widgetId = 'fleet-ops-issues-insights-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get categoryDatasets() {
        if (!this.data?.by_category?.data?.length) return null;
        return [
            {
                data: this.data.by_category.data,
                backgroundColor: this.data.by_category.data.map((_, i) => CATEGORY_PALETTE[i % CATEGORY_PALETTE.length]),
                borderWidth: 0,
            },
        ];
    }

    get categoryLabels() {
        return this.data?.by_category?.labels ?? [];
    }

    get categoryOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 10, padding: 6, font: { size: 10 } } },
            },
        };
    }

    get resolutionDisplay() {
        const hours = this.data?.avg_resolution_hours;
        if (typeof hours !== 'number') return '—';
        if (hours < 1) return `${Math.round(hours * 60)}m`;
        if (hours < 48) return `${hours}h`;
        return `${(hours / 24).toFixed(1)}d`;
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/issues-insights', { period: '30d' });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load issues';
        }
    }
}
