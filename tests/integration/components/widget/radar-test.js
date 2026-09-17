import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';
import Service from '@ember/service';
import EmberRouter from '@ember/routing/router';

class RadarTestRouter extends EmberRouter {
    location = 'none';
    rootURL = '/';
    static dslCallbacks = [];
}

// Dashboard widgets use the host router, which has no bare management.index route.
RadarTestRouter.map(function () {
    this.route('console', { path: '/' }, function () {
        this.route('fleet-ops', function () {
            this.route('management', { path: '/manage' }, function () {});
        });
    });
});

class StubFetchService extends Service {
    response = { summary: { open: 32, overdue: 3, snoozed: 6, critical: 2 }, counts: {} };
    lastUrl = null;
    shouldFail = false;

    async get(url) {
        this.lastUrl = url;
        if (this.shouldFail) {
            throw new Error('offline');
        }
        return this.response;
    }
}

module('Integration | Component | widget/radar', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    hooks.beforeEach(function () {
        this.owner.register('router:main', RadarTestRouter);
        this.owner.setupRouter();
        this.owner.register('service:fetch', StubFetchService);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('it shows the open, overdue and snoozed counts from the summary endpoint', async function (assert) {
        await render(hbs`<Widget::Radar />`);
        await waitFor('[data-test-radar-widget-open]');

        assert.strictEqual(this.fetch.lastUrl, 'fleet-ops/radar/summary');
        assert.dom('[data-test-radar-widget-open]').hasText('32');
        assert.dom('[data-test-radar-widget-overdue]').includesText('3 overdue');
        assert.dom('[data-test-radar-widget-snoozed]').hasText('6 snoozed');
        assert.dom('[data-test-radar-widget]').hasClass('kpi-accent-bad');
        assert.dom('[data-test-radar-widget-link]').hasAttribute('href', '/fleet-ops/manage');
    });

    test('it links to Radar when rendered within the mounted engine', async function (assert) {
        this.owner.mountPoint = 'console.fleet-ops';

        await render(hbs`<Widget::Radar />`);
        await waitFor('[data-test-radar-widget-link]');

        assert.dom('[data-test-radar-widget-link]').hasAttribute('href', '/fleet-ops/manage');
    });

    test('it reads as all clear with nothing open and reports a failed load', async function (assert) {
        this.fetch.response = { summary: { open: 0, overdue: 0, snoozed: 0, critical: 0 } };
        await render(hbs`<Widget::Radar />`);
        await waitFor('[data-test-radar-widget-open]');
        assert.dom('[data-test-radar-widget]').hasClass('kpi-accent-good');
        assert.dom('[data-test-radar-widget-overdue]').doesNotExist();

        this.fetch.shouldFail = true;
        await render(hbs`<Widget::Radar />`);
        await waitFor('.text-red-500, .text-red-400');
        assert.dom('[data-test-radar-widget]').includesText('offline');
    });
});
