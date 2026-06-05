import Component from '@glimmer/component';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

let revenueTrendResponse;

class StubFetchService extends Service {
    async get() {
        return revenueTrendResponse;
    }
}

class ChartStubComponent extends Component {
    get revenueData() {
        return this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data.join(',');
    }

    get revenueTick() {
        return this.args.options.scales.y.ticks.callback(this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data[0]);
    }

    get revenueTooltip() {
        return this.args.options.plugins.tooltip.callbacks.label({
            dataset: { label: 'Revenue' },
            parsed: { y: this.args.datasets.find((dataset) => dataset.label === 'Revenue')?.data[0] },
        });
    }
}

module('Integration | Component | widget/revenue-trend', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        revenueTrendResponse = {
            labels: ['2026-06-01'],
            datasets: [{ label: 'Revenue', data: [267545] }],
            summary: { total: 267545, currency: 'USD', delta_pct: 0 },
        };

        this.owner.register('service:fetch', StubFetchService);
        this.owner.register(
            'component:chart',
            setComponentTemplate(
                hbs`
                    <div data-test-revenue-data>{{this.revenueData}}</div>
                    <div data-test-revenue-tick>{{this.revenueTick}}</div>
                    <div data-test-revenue-tooltip>{{this.revenueTooltip}}</div>
                `,
                ChartStubComponent
            )
        );
    });

    test('it normalizes minor-unit revenue data for chart rendering', async function (assert) {
        await render(hbs`<Widget::RevenueTrend />`);
        await waitFor('[data-test-revenue-data]');

        assert.dom('[data-test-revenue-data]').hasText('2675.45');
        assert.dom('[data-test-revenue-tick]').hasText('$2,675.45');
        assert.dom('[data-test-revenue-tooltip]').hasText('Revenue: $2,675.45');
    });

    test('it uses currency precision when normalizing chart revenue', async function (assert) {
        revenueTrendResponse = {
            labels: ['2026-06-01'],
            datasets: [{ label: 'Revenue', data: [267545] }],
            summary: { total: 267545, currency: 'JPY', delta_pct: 0 },
        };

        await render(hbs`<Widget::RevenueTrend />`);
        await waitFor('[data-test-revenue-data]');

        assert.dom('[data-test-revenue-data]').hasText('267545');

        revenueTrendResponse = {
            labels: ['2026-06-01'],
            datasets: [{ label: 'Revenue', data: [1234567] }],
            summary: { total: 1234567, currency: 'KWD', delta_pct: 0 },
        };

        await render(hbs`<Widget::RevenueTrend />`);
        await waitFor('[data-test-revenue-data]');

        assert.dom('[data-test-revenue-data]').hasText('1234.567');
    });
});
