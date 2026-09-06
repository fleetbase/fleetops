import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function iconButton(icon) {
    return find(`svg[data-icon="${icon}"]`)?.closest('button') ?? null;
}

function address(kind) {
    return find(`.order-listing-row-body-address.${kind} .address-text`).textContent.trim();
}

module('Integration | Component | order/kanban-card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:order-actions',
            class extends Service {
                assignDriver(order) {
                    calls.push(['assignDriver', order.public_id]);
                }
            }
        );
        // eslint-disable-next-line ember/no-private-routing-service
        this.owner.lookup('router:main').transitionTo = (route, model) => calls.push(['transitionTo', route, model.public_id]);
    });

    test('it renders the card, its actions, addresses and tracker warnings', async function (assert) {
        this.set('card', {
            public_id: 'order_1',
            tracking: 'FLB-1',
            status: 'dispatched',
            driver_assigned: null,
            tracker_data: { progress: { percentage: 40, completed_stops: 1 }, insights: { is_location_stale: true } },
            payload: { isMultiDrop: false, pickup: { address: '1 Pickup Rd' }, dropoff: { address: '9 Dropoff Ave' } },
            customer: { name: 'Acme', phone: '+1555', photo_url: null },
        });

        await render(hbs`<Order::KanbanCard @card={{this.card}} />`);

        assert.dom('.kanban-card-title').hasText('FLB-1');
        assert.dom('.status-badge').includesText('Dispatched');
        assert.ok(iconButton('user-plus'), 'an unassigned order offers the assign-driver action');
        assert.dom('.order-progress-bar, .order-listing-row-progress').exists();
        assert.dom().includesText('Stale GPS');
        assert.strictEqual(address('start'), '1 Pickup Rd');
        assert.strictEqual(address('end'), '9 Dropoff Ave');
        assert.dom().includesText('Acme');
        assert.dom().includesText('+1555');
        assert.dom().includesText('No Driver');
        assert.dom().includesText('No Phone');

        await click(iconButton('user-plus'));
        await click(iconButton('eye'));
        assert.deepEqual(this.calls, [
            ['assignDriver', 'order_1'],
            ['transitionTo', 'operations.orders.index.details', 'order_1'],
        ]);

        this.set('card', {
            ...this.card,
            driver_assigned: { name: 'Sam', phone: '+1666', photo_url: null, online: true },
            tracker_data: { progress: { percentage: 100, completed_stops: 3 }, fallback_provider: 'osrm' },
            payload: { isMultiDrop: true, firstWaypoint: { address: 'First stop' }, lastWaypoint: { address: 'Last stop' } },
            customer: null,
        });
        await settled();
        assert.notOk(iconButton('user-plus'), 'an assigned order hides the assign-driver action');
        assert.dom().includesText('Fallback ETA');
        assert.dom().doesNotIncludeText('Stale GPS');
        assert.strictEqual(address('start'), 'First stop');
        assert.strictEqual(address('end'), 'Last stop');
        assert.dom().includesText('Sam');
        assert.dom().includesText('No Customer');
        assert.dom('.resource-assigned-photo svg[data-icon="circle"]').hasClass('text-green-500');

        this.set('card', { ...this.card, tracker_data: { confidence: 'low' } });
        await settled();
        assert.dom().includesText('Low ETA Confidence');

        this.set('card', { ...this.card, tracker_data: { confidence: 'high' } });
        await settled();
        assert.dom().doesNotIncludeText('ETA');
        assert.strictEqual(findAll('.status-badge').length, 1, 'only the status badge remains');
    });
});
