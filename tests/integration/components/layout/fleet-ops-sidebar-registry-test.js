import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, waitFor, waitUntil } from '@ember/test-helpers';
import { setupWindowMock } from 'ember-window-mock/test-support';
import window from 'ember-window-mock';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import templateOnly from '@ember/component/template-only';
import StubEventedService from 'dummy/utils/stub-evented-service';

/**
 * Covers what the main fleet-ops-sidebar suite does not: items and panels registered by other
 * extensions through the universe menu registry (root items, per-section items, in-place footer
 * components, nested panels) and the remote search provider.
 */

const ITEM = '.next-sidebar-navigator-view-in .next-sidebar-navigator-item';

function item(n) {
    return findAll(ITEM)[n - 1];
}

function itemLabels() {
    return findAll(`${ITEM} .next-sidebar-navigator-item-label`).map((element) => element.textContent.trim());
}

function resultLabels() {
    return findAll('.next-sidebar-navigator-search-result-label').map((element) => element.textContent.trim());
}

class RouterStubService extends Service {
    currentRouteName = 'console.fleet-ops.operations.orders.index';
    currentURL = '/fleet-ops';
    handlers = {};

    on(eventName, handler) {
        this.handlers[eventName] = handler;
    }

    off(eventName) {
        delete this.handlers[eventName];
    }

    transitionTo() {
        return Promise.resolve();
    }
}

class AbilitiesStub extends Service {
    can() {
        return true;
    }

    cannot() {
        return false;
    }
}

class UniverseStub extends StubEventedService {
    transitions = [];

    transitionMenuItem(route, menuItem) {
        this.transitions.push({ route, menuItem });
    }
}

class FetchStub extends Service {
    searchCalls = [];
    response = { results: [] };
    error = null;

    get(path, ...rest) {
        // the footer's operations monitor fetches through the same service
        if (path !== 'search') {
            return Promise.resolve({ drivers: [], vehicles: [], fleets: [] });
        }

        this.searchCalls.push([path, ...rest]);

        if (this.error) {
            return Promise.reject(this.error);
        }

        return Promise.resolve(this.response);
    }
}

const FooterStub = setComponentTemplate(hbs`<div class="registry-footer-stub">registry footer</div>`, templateOnly());

const REGISTRY = {
    items: [
        // root-level registered items, out of registration order to prove priority sorting
        { title: 'Reports', slug: 'reports', icon: 'chart-pie', priority: 5, visible: true, keywords: ['insights'] },
        // same priority as Reports: registration order breaks the tie
        { label: 'Audits', slug: 'audits', icon: 'clipboard', priority: 5, visible: true },
        { intl: 'menu.orders', slug: 'orders-registry', icon: 'box', priority: 1, visible: true, description: 'Registered orders' },
        // an in-place component renders in the footer instead of the menu
        { title: 'Root Widget', slug: 'root-widget', renderComponentInPlace: true, component: 'registry-footer-stub' },
        // one item per section so every section list is exercised
        { title: 'Ops Extra', slug: 'ops-extra', section: 'operations', priority: 99 },
        { title: 'Contracts', slug: 'contracts', section: 'management', priority: -10 },
        { title: 'Resources Widget', slug: 'resources-widget', section: 'management', renderComponentInPlace: true, component: 'registry-footer-stub' },
        // a registered item may pin itself first, like the core hub items
        { title: 'Inspections', slug: 'inspections', section: 'maintenance', pinnedFirst: true },
        { title: 'Recalls', slug: 'recalls', section: 'maintenance' },
        { title: 'Tyres', slug: 'tyres', section: 'maintenance' },
        { title: 'Beacons', slug: 'beacons', section: 'connectivity' },
        { title: 'Forecasts', slug: 'forecasts', section: 'analytics' },
        { title: 'Webhooks', slug: 'webhooks', section: 'settings' },
    ],
    panels: [
        { title: 'Partner Panel', slug: 'partner-panel', icon: 'handshake', priority: 20, visible: true, items: [{ title: 'Partner Child', slug: 'partner-child', section: 'management' }] },
        { id: 'early-panel', intl: 'menu.orders', icon: 'box', priority: 10, visible: true },
        {
            title: 'Panel Widget Host',
            slug: 'panel-widget-host',
            priority: 30,
            visible: true,
            items: [{ title: 'Hidden In Place', renderComponentInPlace: true, component: 'registry-footer-stub' }],
        },
    ],
};

module('Integration | Component | layout/fleet-ops-sidebar (registry and search)', function (hooks) {
    setupRenderingTest(hooks);
    setupWindowMock(hooks);

    hooks.beforeEach(function () {
        this.registry = { items: REGISTRY.items, panels: REGISTRY.panels };

        const registry = this.registry;

        class MenuServiceStub extends Service {
            calls = [];

            getMenuItems(registryName) {
                this.calls.push(['getMenuItems', registryName]);
                return registry.items;
            }

            getMenuPanels(registryName) {
                this.calls.push(['getMenuPanels', registryName]);
                return registry.panels;
            }
        }

        this.owner.register('service:router', RouterStubService);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register('service:universe', UniverseStub);
        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:fetch', FetchStub);
        this.owner.register('component:registry-footer-stub', FooterStub);

        this.universe = this.owner.lookup('service:universe');
        this.fetch = this.owner.lookup('service:fetch');
        this.menuService = this.owner.lookup('service:universe/menu-service');

        // search results portal into #application-root-wormhole; keep it inside the test root
        this.wormholeRoot = document.createElement('div');
        this.wormholeRoot.id = 'application-root-wormhole';
        document.getElementById('ember-testing').appendChild(this.wormholeRoot);
    });

    hooks.afterEach(function () {
        this.wormholeRoot.remove();
    });

    test('registered root items and panels follow the core branches, sorted by priority', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.deepEqual(
            this.menuService.calls,
            [
                ['getMenuItems', 'engine:fleet-ops'],
                ['getMenuPanels', 'engine:fleet-ops'],
            ],
            'both registries are read once for this engine'
        );

        const labels = itemLabels();
        assert.deepEqual(labels.slice(0, 6), ['Operations', 'Resources', 'Maintenance', 'Connectivity', 'Analytics', 'Settings'], 'core branches stay first');
        assert.deepEqual(
            labels.slice(6),
            ['Orders', 'Reports', 'Audits', 'Partner Panel'],
            'registered items by priority (ties keep registration order), then panels; intl keys resolve to their translation and label-only items use their label'
        );
        assert.notOk(labels.includes('Panel Widget Host'), 'a panel whose only item renders in place has nothing to navigate to and is left out');
        assert.dom('.registry-footer-stub').exists({ count: 1 }, 'the root in-place component renders in the footer, not the menu');
        assert.dom(item(8)).includesText('Reports');
        assert.dom('svg[data-icon="chart-pie"]', item(8)).exists();
    });

    test('registered section items merge into their branch and in-place components move to that branch footer', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click(item(2));
        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.deepEqual(itemLabels().slice(0, 3), ['Resources Hub', 'Contracts', 'Drivers'], 'a negative-priority registered item sorts ahead of core items');
        assert.notOk(itemLabels().includes('Resources Widget'), 'in-place items are not menu entries');
        assert.dom('.registry-footer-stub').exists({ count: 1 }, 'the management in-place component renders in the Resources footer');

        await click('.next-sidebar-navigator-back');
        await click(item(1));
        assert.ok(itemLabels().includes('Ops Extra'), 'operations items are merged');
        assert.strictEqual(itemLabels().at(-1), 'Ops Extra', 'and a high priority sorts last');

        for (const [branch, expected] of [
            [4, 'Beacons'],
            [5, 'Forecasts'],
            [6, 'Webhooks'],
        ]) {
            await click('.next-sidebar-navigator-back');
            await click(item(branch));
            assert.ok(itemLabels().includes(expected), `${expected} is merged into branch ${branch}`);
        }

        await click('.next-sidebar-navigator-back');
        await click(item(3));
        assert.deepEqual(itemLabels().slice(0, 2), ['Maintenance Hub', 'Inspections'], 'a pinned registered item joins the pinned hub ahead of everything else');
        const maintenance = itemLabels();
        assert.strictEqual(maintenance.indexOf('Tyres'), maintenance.indexOf('Recalls') + 1, 'unprioritised registered items keep their registration order relative to each other');
    });

    test('registered panels open as nested menus and their children transition through the universe', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click(item(10));

        assert.dom('.next-sidebar-navigator-back').includesText('Partner Panel');
        assert.deepEqual(itemLabels(), ['Partner Child']);

        await click(item(1));

        assert.strictEqual(this.universe.transitions.length, 1);
        const [{ route, menuItem }] = this.universe.transitions;
        assert.strictEqual(route, 'console.fleet-ops.virtual');
        assert.true(menuItem._virtual);
        assert.strictEqual(menuItem.slug, 'partner-child');
        assert.deepEqual(menuItem.keywords, ['partner-child', 'management', 'Partner Child']);
    });

    test('the default orders landing opens nested only when entered from a non-root URL', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.operations.orders.index';
        router.currentURL = '/fleet-ops/orders?layout=map';

        await render(hbs`<Layout::FleetOpsSidebar />`);
        assert.dom('.next-sidebar-navigator-back').includesText('Operations', 'a deep orders URL syncs into the Operations menu');

        router.currentURL = '/fleet-ops/';
        await render(hbs`<Layout::FleetOpsSidebar />`);
        assert.dom('.next-sidebar-navigator-back').doesNotExist('the root URL (trailing slash tolerated) stays on the root menu');

        router.currentRouteName = 'console.fleet-ops.operations.scheduler.index';
        router.currentURL = '/fleet-ops';
        await render(hbs`<Layout::FleetOpsSidebar />`);
        assert.dom('.next-sidebar-navigator-back').includesText('Operations', 'at the root URL any other operations route still opens nested');

        // Ember's RouterService reports currentURL as null until the first transition settles.
        router.currentRouteName = 'console.fleet-ops.operations.orders.index';
        router.currentURL = null;
        await render(hbs`<Layout::FleetOpsSidebar />`);
        assert.dom('.next-sidebar-navigator-back').includesText('Operations', 'with no URL yet the orders route is not treated as the root landing');
    });

    test('the primary action creates an order only when a handler is given', async function (assert) {
        this.set('created', 0);
        this.set('onClickCreateOrder', () => this.set('created', this.created + 1));

        await render(hbs`<Layout::FleetOpsSidebar @onClickCreateOrder={{this.onClickCreateOrder}} />`);
        await click('.next-sidebar-navigator-primary-action');
        assert.strictEqual(this.created, 1);

        await render(hbs`<Layout::FleetOpsSidebar />`);
        await click('.next-sidebar-navigator-primary-action');
        assert.strictEqual(this.created, 1, 'without a handler the click is a no-op');
    });

    test('a registered item that matches the current virtual route is active', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.virtual';
        router.currentURL = '/fleet-ops/management/contracts';
        window.location.href = '/fleet-ops/management/contracts';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom(item(2)).includesText('Contracts').hasClass('is-active');
    });

    test('the search provider asks the API and merges its results after the local ones', async function (assert) {
        this.fetch.response = { results: [{ label: 'Remote Order 42', description: 'from the search API', icon: 'box' }] };

        await render(hbs`<Layout::FleetOpsSidebar />`);
        await fillIn('.next-sidebar-navigator-search input', '  orders  ');
        await waitUntil(() => resultLabels().includes('Remote Order 42'));

        assert.deepEqual(this.fetch.searchCalls, [['search', { query: 'orders', limit: 12 }, { namespace: 'int/v1' }]], 'the query is trimmed and the navigator limit forwarded');
        assert.ok(resultLabels().indexOf('Orders') < resultLabels().indexOf('Remote Order 42'), 'local matches come first');
    });

    test('a search API response without results contributes nothing', async function (assert) {
        this.fetch.response = {};

        await render(hbs`<Layout::FleetOpsSidebar />`);
        await fillIn('.next-sidebar-navigator-search input', 'orders');
        await waitFor('.next-sidebar-navigator-search-result');
        await waitUntil(() => this.fetch.searchCalls.length === 1);

        assert.ok(resultLabels().includes('Orders'), 'local matches still show');
        assert.notOk(
            resultLabels().some((label) => label.startsWith('Remote')),
            'nothing remote was added'
        );
    });

    test('a failing search API is swallowed and local results still show', async function (assert) {
        this.fetch.error = new Error('offline');

        await render(hbs`<Layout::FleetOpsSidebar />`);
        await fillIn('.next-sidebar-navigator-search input', 'reports');
        await waitFor('.next-sidebar-navigator-search-result');
        await waitUntil(() => this.fetch.searchCalls.length === 1);

        assert.ok(resultLabels().includes('Reports'), 'the registered Reports item still matches locally');
        assert.notOk(
            resultLabels().some((label) => label.startsWith('Remote')),
            'nothing remote was added'
        );
    });
});
