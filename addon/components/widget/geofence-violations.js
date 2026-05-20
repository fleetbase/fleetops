import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class WidgetGeofenceViolationsComponent extends Component {
    static widgetId = 'fleet-ops-geofence-violations-widget';

    @service fetch;

    @tracked data = null;
    @tracked error = null;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get zoneDatasets() {
        if (!this.data?.by_zone?.data?.length) return null;
        return [
            {
                label: 'Events',
                data: this.data.by_zone.data,
                backgroundColor: '#f59e0b',
            },
        ];
    }

    get zoneLabels() {
        return this.data?.by_zone?.labels ?? [];
    }

    get zoneOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 } },
                y: { grid: { display: false } },
            },
        };
    }

    @task *load() {
        try {
            this.data = yield this.fetch.get('fleet-ops/analytics/geofence-violations', { period: '7d' });
            this.error = null;
        } catch (e) {
            this.error = e?.message ?? 'Failed to load geofence data';
        }
    }
}
