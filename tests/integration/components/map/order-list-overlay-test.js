import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

function makeOrder(id, extra = {}) {
    return {
        public_id: id,
        tracking: id.toUpperCase(),
        status: 'dispatched',
        payload: { pickup: { address: `${id} pickup` }, dropoff: { address: `${id} dropoff` } },
        customer: { name: 'Acme' },
        driver_assigned: null,
        tracker_data: { progress: { percentage: 0 } },
        loadTrackerData() {},
        ...extra,
    };
}

function menuItem(text) {
    return findAll('.next-dd-item').find((el) => el.textContent.trim() === text);
}

module('Integration | Component | map/order-list-overlay', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const active = makeOrder('order_1');
        const driverJob = makeOrder('order_2');
        const fleet = {
            name: 'North',
            drivers_online_count: 1,
            drivers_count: 2,
            drivers: [{ name: 'Sam Driver', vehicle_avatar: 'https://cdn.example.com/van.png', _panelActiveJobs: [driverJob] }],
        };
        this.active = active;
        this.fleet = fleet;

        class OverlayStub extends Service {
            @tracked isOpen = true;
            @tracked width = 400;
            @tracked searchQuery = '';
            @tracked loaded = true;
            @tracked selectedOrders = [];
            @tracked activeOrders = [active];
            @tracked unassignedOrders = [];
            @tracked fleets = [fleet];
            @tracked load = { isRunning: false };

            get orderGroups() {
                return { activeOrders: this.activeOrders, unassignedOrders: this.unassignedOrders };
            }

            handleLoad = (overlay) => {
                calls.push(['handleLoad', typeof overlay.close]);
            };

            close = () => {
                calls.push(['close']);
            };

            toggleSelectOrder = (order) => {
                calls.push(['toggleSelectOrder', order.public_id]);
                this.selectedOrders = this.selectedOrders.includes(order) ? this.selectedOrders.filter((o) => o !== order) : [...this.selectedOrders, order];
            };
        }
        this.owner.register('service:order-list-overlay', OverlayStub);
        this.owner.register(
            'service:order-actions',
            class extends Service {
                bulkCancel(orders) {
                    calls.push(['bulkCancel', orders.map((o) => o.public_id)]);
                }

                bulkDelete(orders) {
                    calls.push(['bulkDelete', orders.map((o) => o.public_id)]);
                }
            }
        );
        this.owner.register(
            'service:fleet-actions',
            class extends Service {
                modal = { create: () => calls.push(['fleet.modal.create']) };
                panel = { view: (fleet) => calls.push(['fleet.panel.view', fleet.name]) };
            }
        );
        // eslint-disable-next-line ember/no-private-routing-service
        this.owner.lookup('router:main').transitionTo = (route, model) => calls.push(['transitionTo', route, model?.public_id]);
    });

    test('it lists order groups and fleets, and routes the header actions', async function (assert) {
        await render(hbs`<Map::OrderListOverlay @onMouseEnterOrder={{this.noop}} @onMouseLeaveOrder={{this.noop}} />`);

        assert.deepEqual(this.calls, [['handleLoad', 'function']], 'the overlay registers itself with the service once loaded');
        assert.dom('.next-content-overlay').hasClass('is-open');
        assert.dom('.order-list-overlay-search').hasAttribute('placeholder', 'Search orders...');
        assert.dom('.fleetbase-loader').doesNotExist('no spinner while the service is idle');

        const titles = findAll('.panel-title').map((el) => el.textContent.replace(/\s+/g, ' ').trim());
        assert.deepEqual(titles, ['Active Orders 1 Order', 'Unassigned Orders 0 Orders', 'North 1 of 2 Online', 'Sam Driver 1 Order']);
        assert.dom('.order-listings .order-listings-row-container').exists({ count: 1 }, 'the driver panel starts collapsed');
        assert.dom('.order-listing-actions').doesNotExist();

        const triggers = findAll('.next-org-button-trigger');
        assert.strictEqual(triggers.length, 1, 'no selection dropdown without selected orders');
        await click(triggers[0]);
        assert.dom().includesText('Actions');
        await click(menuItem('Create new order...'));
        assert.deepEqual(this.calls.at(-1), ['transitionTo', 'operations.orders.index.new', undefined]);
        await click(findAll('.next-org-button-trigger')[0]);
        await click(menuItem('Create new fleet...'));
        assert.deepEqual(this.calls.at(-1), ['fleet.modal.create']);

        await click(find('.next-content-panel-header .btn-wrapper button'), 'the fleet panel action button');
        assert.deepEqual(this.calls.at(-1), ['fleet.panel.view', 'North']);

        await click('.next-content-overlay-panel-cancel-button');
        assert.deepEqual(this.calls.at(-1), ['close']);
    });

    test('selecting orders reveals the bulk actions and the details shortcut', async function (assert) {
        await render(hbs`<Map::OrderListOverlay />`);

        await click('.order-listings .order-listings-row-container');
        assert.deepEqual(this.calls.at(-1), ['toggleSelectOrder', 'order_1']);
        assert.dom('.order-listings-row-container.selected').exists({ count: 1 });
        assert.dom('.order-listing-actions').exists({ count: 1 });

        const triggers = findAll('.next-org-button-trigger');
        assert.strictEqual(triggers.length, 2);
        assert.dom(triggers[0]).hasClass('has-selections');
        await click(triggers[0]);
        assert.dom().includesText('Selected 1 Order');
        await click(menuItem('Cancel orders...'));
        assert.deepEqual(this.calls.at(-1), ['bulkCancel', ['order_1']]);
        await click(findAll('.next-org-button-trigger')[0]);
        await click(menuItem('Delete orders...'));
        assert.deepEqual(this.calls.at(-1), ['bulkDelete', ['order_1']]);

        await click(findAll('button').find((button) => /Details/.test(button.textContent)));
        assert.deepEqual(this.calls.at(-1), ['transitionTo', 'operations.orders.index.details', 'order_1']);

        const overlay = this.owner.lookup('service:order-list-overlay');
        overlay.loaded = false;
        overlay.load = { isRunning: true };
        await settled();
        assert.dom('.panel-title').doesNotExist('nothing lists until the service has loaded');
        assert.dom('.fleetbase-loader').exists('the search icon becomes a spinner while loading');
    });
});
