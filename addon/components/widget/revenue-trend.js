import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import getCurrency from '@fleetbase/ember-ui/utils/get-currency';
import formatMoney from '@fleetbase/ember-accounting/utils/format-money';

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

    get currencyCode() {
        return this.data?.summary?.currency ?? 'USD';
    }

    get currency() {
        return getCurrency(this.currencyCode) ?? getCurrency('USD');
    }

    get currencyDivisor() {
        const precision = Number(this.currency?.precision ?? 2);

        return precision > 0 ? 10 ** precision : 1;
    }

    get chartDatasets() {
        return (
            this.data?.datasets?.map((dataset) => {
                if (!this.isRevenueDataset(dataset)) {
                    return dataset;
                }

                return {
                    ...dataset,
                    data: dataset.data?.map((value) => this.normalizeRevenueValue(value)) ?? [],
                };
            }) ?? []
        );
    }

    isRevenueDataset(dataset) {
        return dataset?.label === 'Revenue' || dataset?.yAxisID === 'y';
    }

    normalizeRevenueValue(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return numericValue / this.currencyDivisor;
    }

    formatChartCurrency(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return value;
        }

        return formatMoney(numericValue, this.currency.symbol, this.currency.precision, this.currency.thousandSeparator, this.currency.decimalSeparator);
    }

    get chartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: (context) => {
                            if (this.isRevenueDataset(context.dataset)) {
                                return `Revenue: ${this.formatChartCurrency(context.parsed?.y ?? context.raw)}`;
                            }

                            return `${context.dataset?.label ?? 'Value'}: ${context.parsed?.y ?? context.raw}`;
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: this.currency.precision,
                        callback: (value) => this.formatChartCurrency(value),
                    },
                },
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
