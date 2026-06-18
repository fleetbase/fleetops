import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import window from 'ember-window-mock';
import { getOwner } from '@ember/application';

class RouterStubService extends Service {
    currentRouteName = 'console.fleet-ops.operations.orders';
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

        class UniverseStub extends Service {
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

        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(2)');

        const labels = [...this.element.querySelectorAll('.next-sidebar-navigator-view-in .next-sidebar-navigator-item-label')].map((element) => element.textContent.trim());

        assert.deepEqual(labels.slice(0, 5), ['Resources Hub', 'Contracts', 'Drivers', 'Permits', 'Vehicles'], 'hub items stay first while registered section items sort by priority');

        await click('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(2)');

        assert.dom('.next-sidebar-navigator-back').includesText('Resources');
        assert.dom('.next-sidebar-navigator-view-in .next-sidebar-navigator-item:nth-of-type(2)').hasClass('is-active');
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
