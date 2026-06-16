import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class RouterStubService extends Service {
    currentRouteName = 'console.fleet-ops.operations.orders';
    currentURL = '/fleet-ops';
    transitions = [];

    on() {}

    off() {}

    transitionTo(route, ...args) {
        this.currentRouteName = route;
        this.transitions.push({ route, args });
        return Promise.resolve();
    }
}

module('Integration | Component | layout/fleet-ops-sidebar', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:router', RouterStubService);
    });

    test('it renders the FleetOps navigator shell', async function (assert) {
        this.set('createOrder', () => assert.step('create-order'));

        await render(hbs`<Layout::FleetOpsSidebar @onClickCreateOrder={{this.createOrder}} />`);

        assert.dom('.fleet-ops-sidebar-navigator').exists();
        assert.dom('.next-sidebar-navigator-primary-action').includesText('Create');
        assert.dom('.next-sidebar-navigator-primary-action').hasClass('fleet-ops-sidebar-primary-action');
        assert.dom('.next-sidebar-navigator-search input').hasAttribute('placeholder', 'Search Fleet-Ops...');
        assert.dom('.next-sidebar-navigator-item').includesText('Operations');
        assert.dom('.next-sidebar-navigator-item').includesText('Resources');
        assert.dom('.next-sidebar-navigator-item').includesText('Maintenance');
        assert.dom('.next-sidebar-navigator-item').includesText('Connectivity');
        assert.dom('.next-sidebar-navigator-item').includesText('Analytics');
        assert.dom('.next-sidebar-navigator-item').includesText('Settings');

        await click('.next-sidebar-navigator-primary-action');

        assert.verifySteps(['create-order']);
    });

    test('it labels the operations landing as Orders and exposes live map keywords', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type');

        assert.dom('.next-sidebar-navigator-back').includesText('Operations');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').includesText('Orders');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').doesNotIncludeText('Dashboard');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="map-location-dot"]').exists();

        await fillIn('.next-sidebar-navigator-search input', 'live map');
        await waitFor('.next-sidebar-navigator-search-result');

        assert.dom('.next-sidebar-navigator-search-result').includesText('Orders');
    });

    test('it routes nested branches to hub defaults', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        const router = this.owner.lookup('service:router');

        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(2)');
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.management.index');

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(3)');
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.maintenance.index');

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(4)');
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.connectivity.telematics');

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(5)');
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.analytics.index');

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(6)');
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.settings.index');
    });

    test('it exposes section hubs as first nested menu items', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(2)');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').includesText('Resources Hub');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="layer-group"]').exists();

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(3)');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').includesText('Maintenance Hub');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="wrench"]').exists();

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(5)');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').includesText('Dashboard');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="chart-line"]').exists();

        await click('.next-sidebar-navigator-back');
        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(6)');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type').includesText('Settings Hub');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="sliders"]').exists();
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:first-of-type svg[data-icon="table-cells-large"]').doesNotExist();
    });

    test('it keeps block usage backwards compatible', async function (assert) {
        await render(hbs`
            <Layout::FleetOpsSidebar>
                template block text
            </Layout::FleetOpsSidebar>
        `);

        assert.dom(this.element).includesText('template block text');
    });
});
