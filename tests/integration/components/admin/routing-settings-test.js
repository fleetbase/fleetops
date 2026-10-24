import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class FetchStubService extends Service {
    posts = [];

    get(url) {
        if (url === 'fleet-ops/settings/admin-routing-providers') {
            return Promise.resolve({
                providers: { google: false, valhalla: true, osrm: true, vroom: true },
                defaults: { display_engine: 'valhalla', optimization_engine: 'vroom' },
                server_routing: 'road',
                exceptions: [{ company_uuid: 'company-1', company_name: 'Acme Logistics', providers: ['google'] }],
                available_providers: [
                    { key: 'google', label: 'Google' },
                    { key: 'valhalla', label: 'Valhalla' },
                    { key: 'osrm', label: 'OSRM' },
                    { key: 'vroom', label: 'VROOM' },
                ],
            });
        }

        return Promise.resolve({ traffic_enabled: false, cache_ttl_seconds: 45 });
    }

    post(url, body) {
        this.posts.push([url, body]);
        return Promise.resolve(body);
    }
}

class NotificationsStubService extends Service {
    successes = [];

    success(message) {
        this.successes.push(message);
    }

    serverError() {}
}

class RouteEngineStubService extends Service {
    allServices = [
        { key: 'osrm', name: 'OSRM' },
        { key: 'valhalla', name: 'Valhalla' },
    ];
}

class RouteOptimizationStubService extends Service {
    allServices = [
        { key: 'osrm', name: 'OSRM' },
        { key: 'vroom', name: 'VROOM' },
    ];
}

module('Integration | Component | admin/routing-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:route-engine', RouteEngineStubService);
        this.owner.register('service:route-optimization', RouteOptimizationStubService);
    });

    test('it loads the provider policy and saves provider access, defaults and exceptions', async function (assert) {
        await render(hbs`<div id="next-view-section-subheader-actions"></div><Admin::RoutingSettings />`);

        assert.dom('[data-test-provider-access]').exists({ count: 4 });
        assert.dom('[data-test-provider-access="google"]').includesText('Billed per request');
        assert.dom('[data-test-provider-exception="company-1"]').includesText('Acme Logistics');

        // Switch Google on for everyone, then remove the now-redundant exception
        await click('[data-test-provider-access="google"] [role="checkbox"]');
        await click('[data-test-provider-exception="company-1"] button.btn-danger');
        await click('#next-view-section-subheader-actions button');

        const fetch = this.owner.lookup('service:fetch');
        assert.deepEqual(fetch.posts[0], [
            'fleet-ops/settings/admin-routing-providers',
            {
                providers: { google: true, valhalla: true, osrm: true, vroom: true },
                defaults: { display_engine: 'valhalla', optimization_engine: 'vroom' },
                server_routing: 'road',
                exceptions: [],
            },
        ]);
        // Tracking only saves behaviour; its provider is the routing engine
        assert.strictEqual(fetch.posts[1][0], 'fleet-ops/settings/admin-tracking-settings');
        assert.false('provider' in fetch.posts[1][1]);
        assert.false(fetch.posts[1][1].traffic_enabled);
        assert.strictEqual(fetch.posts[1][1].cache_ttl_seconds, 45);
        assert.deepEqual(this.owner.lookup('service:notifications').successes, ['Routing settings saved.']);
    });
});
