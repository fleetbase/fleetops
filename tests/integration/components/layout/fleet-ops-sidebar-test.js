import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, settled, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import window from 'ember-window-mock';
import { setupWindowMock } from 'ember-window-mock/test-support';
import { getOwner } from '@ember/application';
import StubEventedService from 'dummy/utils/stub-evented-service';

// The navigator renders the nested view's back control as a sibling <button> of the item buttons,
// so `:first-of-type` / `:nth-of-type(n)` count it too. Address items by their own index instead.
const ITEM = '.next-sidebar-navigator-view-in .next-sidebar-navigator-item';
function item(n) {
    return findAll(ITEM)[n - 1];
}

class RouterStubService extends Service {
    currentRouteName = 'console.fleet-ops.operations.orders.index';
    currentURL = '/fleet-ops';
    transitions = [];
    handlers = {};

    on(eventName, handler) {
        this.handlers[eventName] = handler;
    }

    off(eventName) {
        delete this.handlers[eventName];
    }

    transitionTo(route, ...args) {
        this.currentRouteName = route;
        this.transitions.push({ route, args });
        this.triggerRouteDidChange();
        return Promise.resolve();
    }

    triggerRouteDidChange() {
        this.handlers.routeDidChange?.();
    }
}

class AbilitiesStub extends Service {
    denied = new Set();

    can(permission) {
        return !this.denied.has(permission);
    }

    cannot(permission) {
        return !this.can(permission);
    }
}

module('Integration | Component | layout/fleet-ops-sidebar', function (hooks) {
    setupRenderingTest(hooks);
    // Without this the `window` import above is the REAL window: the virtual-route test's
    // `window.location.href = ...` navigates the browser away from the test harness, testem loses
    // it, and the run dies with "Browser timeout exceeded: 120s" during the next test.
    setupWindowMock(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:router', RouterStubService);
        this.owner.register('service:abilities', AbilitiesStub);

        // The navigator portals its search results into #application-root-wormhole (falling back
        // to document.body). Mount that inside the testing container: qunit-dom and waitFor scope
        // to the test root, so a portal appended to the body is invisible to every assertion.
        this.wormholeRoot = document.getElementById('application-root-wormhole');

        if (!this.wormholeRoot) {
            this.wormholeRoot = document.createElement('div');
            this.wormholeRoot.id = 'application-root-wormhole';
            (document.getElementById('ember-testing') ?? document.body).appendChild(this.wormholeRoot);
            this.createdWormholeRoot = true;
        }
    });

    hooks.afterEach(function () {
        if (this.createdWormholeRoot) {
            this.wormholeRoot.remove();
        }
    });

    test('it renders the FleetOps navigator shell', async function (assert) {
        this.set('createOrder', () => assert.step('create-order'));

        await render(hbs`<Layout::FleetOpsSidebar @onClickCreateOrder={{this.createOrder}} />`);

        assert.dom('.fleet-ops-sidebar-navigator').exists();
        assert.dom('.next-sidebar-navigator-primary-action').includesText('Create');
        assert.dom('.next-sidebar-navigator-primary-action').hasClass('fleet-ops-sidebar-primary-action');
        assert.dom('.next-sidebar-navigator-search input').hasAttribute('placeholder', 'Search Fleet-Ops...');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Operations');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Resources');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Maintenance');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Connectivity');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Analytics');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Settings');

        await click('.next-sidebar-navigator-primary-action');

        assert.verifySteps(['create-order']);
    });

    test('it starts on the root menu for the default FleetOps landing route', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.operations.orders.index.index';
        router.currentURL = '/fleet-ops';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').doesNotExist();
        assert.dom(item(1)).includesText('Operations');
        assert.dom(item(1)).hasClass('is-parent-active');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Resources');
        assert.dom('.fleet-ops-operations-monitor').exists('Live Operations remains visible on the root menu');

        router.currentRouteName = 'console.fleet-ops.management.vehicles.index';
        router.currentURL = '/fleet-ops/manage/vehicles';
        router.triggerRouteDidChange();
        await settled();

        assert.dom('.next-sidebar-navigator-back').includesText('Resources', 'later route changes still sync nested state normally');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Vehicles');
    });

    test('it treats root URL variants as FleetOps root entry', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.operations.orders.index.index';
        router.currentURL = '/fleet-ops/?from=console-home';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').doesNotExist();
        assert.dom(item(1)).includesText('Operations');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Resources');
    });

    test('clicking Operations from root keeps the user-driven nested context', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.operations.orders.index.index';
        router.currentURL = '/fleet-ops';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').doesNotExist();

        await click(item(1));

        assert.dom('.next-sidebar-navigator-back').includesText('Operations');
        assert.dom(item(1)).includesText('Orders');
    });

    test('it opens the matching nested menu for specific initial FleetOps routes', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.management.vehicles.index';
        router.currentURL = '/fleet-ops/manage/vehicles';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Vehicles');
        assert.dom(item(3)).hasClass('is-active');

        router.currentRouteName = 'console.fleet-ops.connectivity.telematics.index';
        router.currentURL = '/fleet-ops/connectivity/telematics';
        router.triggerRouteDidChange();
        await settled();

        assert.dom('.next-sidebar-navigator-back').includesText('Connectivity');
        assert.dom(item(1)).includesText('Telematics');
        assert.dom(item(1)).hasClass('is-active');

        router.currentRouteName = 'console.fleet-ops.connectivity.devices.index';
        router.currentURL = '/fleet-ops/connectivity/devices';
        router.triggerRouteDidChange();
        await settled();

        assert.dom('.next-sidebar-navigator-back').includesText('Connectivity');
        assert.dom('.next-sidebar-navigator-view-in').includesText('Devices');
    });

    test('it opens Operations nested for non-default order entry routes', async function (assert) {
        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.operations.orders.index.details.index';
        router.currentURL = '/fleet-ops/orders/order_123';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').includesText('Operations');
        assert.dom(item(1)).includesText('Orders');
        assert.dom(item(1)).hasClass('is-active');
    });

    test('it labels the operations landing as Orders and exposes live map keywords', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click(item(1));

        assert.dom('.next-sidebar-navigator-back').includesText('Operations');
        assert.dom(item(1)).includesText('Orders');
        assert.dom(item(1)).doesNotIncludeText('Dashboard');
        assert.dom('svg[data-icon="map-location-dot"]', item(1)).exists();

        await fillIn('.next-sidebar-navigator-search input', 'live map');
        await waitFor('.next-sidebar-navigator-search-result');
        // 'live map' also matches the Operations branch by keyword, which sorts first; the point is
        // that the Orders landing is offered under that name (not "Dashboard").
        const liveMapResults = findAll('.next-sidebar-navigator-search-result-label').map((element) => element.textContent.trim());
        assert.ok(liveMapResults.includes('Orders'), `live map search offers Orders (got ${liveMapResults.join(', ')})`);
    });

    test('it hides FleetOps core items by see permissions even when list permissions exist', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see service-rate');
        abilities.denied.add('fleet-ops see vehicle');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom(item(1)).includesText('Operations');

        await click(item(1));

        assert.dom('.next-sidebar-navigator-back').includesText('Operations');
        assert.dom('.next-sidebar-navigator').includesText('Orders');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Service Rates');

        await click('.next-sidebar-navigator-back');
        await click(item(2));

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Vehicles');

        await fillIn('.next-sidebar-navigator-search input', 'vehicles');
        // The Operations and Resources branches match 'vehicles' by keyword and are still offered;
        // the denied Vehicles item itself must not be.
        await waitFor('.next-sidebar-navigator-search-result');
        const vehicleResults = findAll('.next-sidebar-navigator-search-result-label').map((element) => element.textContent.trim());
        assert.ok(vehicleResults.length > 0, 'branches that mention vehicles are still searchable');
        assert.notOk(vehicleResults.includes('Vehicles'), `denied FleetOps items are excluded from search results (got ${vehicleResults.join(', ')})`);
    });

    test('it hides hub-only FleetOps sections when every real child is denied', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see maintenance-schedule');
        abilities.denied.add('fleet-ops see work-order');
        abilities.denied.add('fleet-ops see maintenance');
        abilities.denied.add('fleet-ops see equipment');
        abilities.denied.add('fleet-ops see part');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-view-in').doesNotIncludeText('Maintenance');

        await fillIn('.next-sidebar-navigator-search input', 'maintenance hub');

        assert.dom('.next-sidebar-navigator-search-result').doesNotExist('hidden hub-only sections are excluded from search');
    });

    test('it keeps section hubs visible when at least one real child is visible', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see maintenance-schedule');
        abilities.denied.add('fleet-ops see maintenance');
        abilities.denied.add('fleet-ops see equipment');
        abilities.denied.add('fleet-ops see part');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-view-in').includesText('Maintenance');

        await click(item(3));

        assert.dom('.next-sidebar-navigator-back').includesText('Maintenance');
        assert.dom(item(1)).includesText('Maintenance Hub');
        assert.dom('.next-sidebar-navigator').includesText('Work Orders');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Schedules');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Equipment');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Parts');
    });

    test('it hides Resources when the Resources Hub is the only visible child', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see driver');
        abilities.denied.add('fleet-ops see vehicle');
        abilities.denied.add('fleet-ops see fleet');
        abilities.denied.add('fleet-ops see vendor');
        abilities.denied.add('fleet-ops see contact');
        abilities.denied.add('fleet-ops see place');
        abilities.denied.add('fleet-ops see fuel-report');
        abilities.denied.add('fleet-ops see issue');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-view-in').doesNotIncludeText('Resources');

        await fillIn('.next-sidebar-navigator-search input', 'resources hub');

        assert.dom('.next-sidebar-navigator-search-result').doesNotExist('Resources Hub is not searchable when Resources has no real visible children');
    });

    test('it hides Connectivity when Telematics is the only visible child', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see fuel-report');
        abilities.denied.add('fleet-ops see device');
        abilities.denied.add('fleet-ops see sensor');
        abilities.denied.add('fleet-ops see device-event');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-view-in').doesNotIncludeText('Connectivity');

        await fillIn('.next-sidebar-navigator-search input', 'connectivity hub');

        assert.dom('.next-sidebar-navigator-search-result').doesNotExist('Telematics is not searchable when Connectivity has no real visible children');
    });

    test('it keeps Connectivity visible when at least one real child is visible', async function (assert) {
        const abilities = this.owner.lookup('service:abilities');
        abilities.denied.add('fleet-ops see fuel-report');
        abilities.denied.add('fleet-ops see sensor');
        abilities.denied.add('fleet-ops see device-event');

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-view-in').includesText('Connectivity');

        await click(item(4));

        assert.dom('.next-sidebar-navigator-back').includesText('Connectivity');
        assert.dom(item(1)).includesText('Telematics');
        assert.dom('.next-sidebar-navigator').includesText('Devices');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Fuel Integrations');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Sensors');
        assert.dom('.next-sidebar-navigator').doesNotIncludeText('Events');
    });

    test('it routes nested branches to hub defaults', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        const router = this.owner.lookup('service:router');

        await click(item(2));
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.management.index');

        await click('.next-sidebar-navigator-back');
        await click(item(3));
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.maintenance.index');

        await click('.next-sidebar-navigator-back');
        await click(item(4));
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.connectivity.telematics');

        await click('.next-sidebar-navigator-back');
        await click(item(5));
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.analytics.index');

        await click('.next-sidebar-navigator-back');
        await click(item(6));
        assert.strictEqual(router.transitions.at(-1).route, 'console.fleet-ops.settings.index');
    });

    test('it exposes section hubs as first nested menu items', async function (assert) {
        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click(item(2));
        assert.dom(item(1)).includesText('Resources Hub');
        assert.dom('svg[data-icon="layer-group"]', item(1)).exists();

        await click('.next-sidebar-navigator-back');
        await click(item(3));
        assert.dom(item(1)).includesText('Maintenance Hub');
        assert.dom('svg[data-icon="wrench"]', item(1)).exists();

        await click('.next-sidebar-navigator-back');
        await click(item(5));
        assert.dom(item(1)).includesText('Dashboard');
        assert.dom('svg[data-icon="chart-line"]', item(1)).exists();

        await click('.next-sidebar-navigator-back');
        await click(item(6));
        assert.dom(item(1)).includesText('Settings Hub');
        assert.dom('svg[data-icon="sliders"]', item(1)).exists();
        assert.dom('svg[data-icon="table-cells-large"]', item(1)).doesNotExist();
    });

    test('it preserves registered item priority, virtual metadata, and nested active state', async function (assert) {
        assert.expect(8);

        const contractsItem = {
            title: 'Contracts',
            slug: 'contracts',
            section: 'management',
            icon: 'file-signature',
            priority: -10,
            visible: true,
        };
        const permitsItem = {
            title: 'Permits',
            slug: 'permits',
            section: 'management',
            icon: 'stamp',
            priority: 1.5,
            visible: true,
        };

        class MenuServiceStub extends Service {
            getMenuItems(registryName) {
                assert.strictEqual(registryName, 'engine:fleet-ops');
                return [permitsItem, contractsItem];
            }

            getMenuPanels(registryName) {
                assert.strictEqual(registryName, 'engine:fleet-ops');
                return [
                    {
                        title: 'Registry Late',
                        icon: 'box',
                        priority: 20,
                        items: [],
                    },
                    {
                        title: 'Registry Early',
                        icon: 'box',
                        priority: 10,
                        items: [],
                    },
                ];
            }
        }

        class UniverseStub extends StubEventedService {
            transitionMenuItem(route, menuItem) {
                assert.strictEqual(route, 'console.fleet-ops.virtual');
                assert.true(menuItem._virtual, 'registered item keeps virtual metadata');
                assert.strictEqual(menuItem.slug, 'contracts');

                const router = getOwner(this).lookup('service:router');
                router.currentRouteName = 'console.fleet-ops.virtual';
                router.currentURL = '/fleet-ops/management/contracts';
                window.location.href = '/fleet-ops/management/contracts';
                router.triggerRouteDidChange();
            }
        }

        this.owner.register('service:universe/menu-service', MenuServiceStub);
        this.owner.register('service:universe', UniverseStub);

        await render(hbs`<Layout::FleetOpsSidebar />`);

        await click(item(2));

        const labels = [...this.element.querySelectorAll('.next-sidebar-navigator-view-in .next-sidebar-navigator-item-label')].map((element) => element.textContent.trim());

        assert.deepEqual(labels.slice(0, 5), ['Resources Hub', 'Contracts', 'Drivers', 'Permits', 'Vehicles'], 'hub items stay first while registered section items sort by priority');

        await click(item(2));

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom(item(2)).hasClass('is-active');
    });

    test('it opens registry item nested context on initial virtual route entry', async function (assert) {
        const contractsItem = {
            title: 'Contracts',
            slug: 'contracts',
            section: 'management',
            icon: 'file-signature',
            priority: -10,
            visible: true,
        };

        class MenuServiceStub extends Service {
            getMenuItems() {
                return [contractsItem];
            }

            getMenuPanels() {
                return [];
            }
        }

        this.owner.register('service:universe/menu-service', MenuServiceStub);

        const router = this.owner.lookup('service:router');
        router.currentRouteName = 'console.fleet-ops.virtual';
        router.currentURL = '/fleet-ops/management/contracts';
        window.location.href = '/fleet-ops/management/contracts';

        await render(hbs`<Layout::FleetOpsSidebar />`);

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom(item(2)).includesText('Contracts');
        assert.dom(item(2)).hasClass('is-active');
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
