import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import formatCurrency from '@fleetbase/ember-ui/utils/format-currency';
import formatMeters from '@fleetbase/ember-ui/utils/format-meters';

/** Subset shown in the compact legacy widget. Full 13 metrics still come back from
 *  the API; users who want the rest should adopt the per-tile KPI widgets. */
const TOP_KEYS = ['earnings', 'orders_completed', 'orders_in_progress', 'drivers_online', 'fuel_costs', 'total_distance_traveled'];

const FORMATTERS = {
    money: (v, currency) => formatCurrency(v ?? 0, currency || 'USD'),
    meters: (v) => formatMeters(v ?? 0),
    count: (v) => v ?? 0,
};

export default class WidgetFleetOpsKeyMetricsComponent extends Component {
    /**
     * The widget ID to use for registering.
     *
     * @memberof WidgetFleetOpsKeyMetricsComponent
     */
    static widgetId = 'fleet-ops-key-metrics-widget';

    /**
     * Inject the fetch service.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @service fetch;

    /**
     * Property for loading metrics to.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @tracked metrics = {};

    /**
     * Creates an instance of WidgetKeyMetricsComponent.
     * @memberof WidgetKeyMetricsComponent
     */
    constructor() {
        super(...arguments);
        this.getDashboardMetrics.perform();
    }

    /**
     * Task which fetches key metrics.
     *
     * @memberof WidgetKeyMetricsComponent
     */
    @task *getDashboardMetrics() {
        this.metrics = yield this.fetch.get('fleet-ops/metrics').then((response) => {
            return this.createMetricsMapFromResponse(response);
        });
    }

    /**
     * Creates a map of metrics from the response data. This method organizes the metrics data into a more usable format.
     *
     * @param {Object} metrics - The metrics object fetched from the server.
     * @returns {Object} A map of metrics where each key is a metric name and its value is an object of metric options.
     * @memberof WidgetKeyMetricsComponent
     */
    createMetricsMapFromResponse(metrics = {}) {
        const keys = Object.keys(metrics);
        const map = {};

        for (let i = 0; i < keys.length; i++) {
            const key = keys[i];
            map[key] = this.createMetricOptionsHash(key, metrics[key]);
        }

        return map;
    }

    /**
     * Creates a hash of options for a given metric. Depending on the metric key, it assigns a specific format.
     *
     * @param {string} key - The key representing the specific metric.
     * @param {number} value - The value of the metric.
     * @returns {Object} An object containing the metric value and its format.
     * @memberof WidgetKeyMetricsComponent
     */
    createMetricOptionsHash(key, value) {
        const options = { value };

        if (key === 'earnings') {
            options.format = 'money';
        }

        if (key === 'fuel_costs') {
            options.format = 'money';
        }

        if (key === 'total_distance_traveled') {
            options.format = 'meters';
        }

        return options;
    }

    /**
     * Pre-formatted top-N entries the compact template renders. Surfaces a fixed
     * priority order rather than dumping every available metric — the per-tile
     * KPI widgets cover the long tail for users who want everything.
     */
    get topMetrics() {
        if (!this.metrics) return [];
        return TOP_KEYS.filter((key) => this.metrics[key] !== undefined).map((key) => {
            const { value, format } = this.metrics[key];
            const formatter = FORMATTERS[format] ?? FORMATTERS.count;
            return { key, formattedValue: formatter(value) };
        });
    }
}
