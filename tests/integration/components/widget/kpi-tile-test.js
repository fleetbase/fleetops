import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class StubFetchService extends Service {
    response = {};
    callCount = 0;
    lastUrl = null;
    lastParams = null;

    async get(url, params) {
        this.callCount += 1;
        this.lastUrl = url;
        this.lastParams = params;
        return this.response;
    }
}

module('Integration | Component | widget/kpi-tile', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', StubFetchService);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('it fetches the per-slug metric endpoint and renders the formatted value', async function (assert) {
        this.fetch.response = {
            slug: 'earnings',
            value: 12500.5,
            currency: 'USD',
            format: 'money',
            delta_pct: 14.2,
            sparkline: { labels: ['2026-05-12', '2026-05-13'], data: [1200, 1300] },
        };

        await render(hbs`
            <Widget::KpiTile
                @slug="earnings"
                @title="Earnings"
                @format="money"
                @showDelta={{true}}
                @showSparkline={{true}}
            />
        `);

        await waitFor('.fleet-ops-kpi-tile');

        assert.strictEqual(this.fetch.callCount, 1, 'fetch invoked once');
        assert.strictEqual(this.fetch.lastUrl, 'fleet-ops/metrics/earnings', 'hits per-slug endpoint');
        assert.dom('.fleet-ops-kpi-tile').exists('tile wrapper rendered');
    });

    test('it inverts delta semantics when @deltaInverse={{true}}', async function (assert) {
        this.fetch.response = {
            slug: 'open_issues',
            value: 12,
            format: 'count',
            delta_pct: 33.3,
        };

        await render(hbs`
            <Widget::KpiTile @slug="open_issues" @title="Open Issues" @format="count"
                             @showDelta={{true}} @deltaInverse={{true}} />
        `);

        await waitFor('.fleet-ops-kpi-tile');

        assert.dom('.fleet-ops-kpi-tile .danger-status-badge, .fleet-ops-kpi-tile [class*="danger"]').exists('positive delta on an inverse metric renders as danger');
    });
});
