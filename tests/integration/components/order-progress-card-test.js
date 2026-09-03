import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function order(overrides = {}) {
    return {
        tracking: 'TRK-1',
        status: 'active',
        createdAt: '2026-01-02',
        isNew: false,
        has_driver_assigned: true,
        tracker_data: { progress: { percentage: 50, completed_stops: 1 }, eta: { active_stop_seconds: 600, completion_at: '10:30' }, active_stop: { address: 'Next Stop' } },
        payload: { pickup: { address: 'Pickup Street' }, dropoff: { address: 'Dropoff Street' } },
        ...overrides,
    };
}

module('Integration | Component | order-progress-card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const errors = (this.errors = []);
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    errors.push(error);
                }
            }
        );
    });

    test('it renders the order summary, progress, insights and addresses, and clicks hand back the order', async function (assert) {
        const clicked = [];
        this.set('order', order());
        this.set('onClick', (row) => clicked.push(row));

        await render(hbs`<OrderProgressCard @order={{this.order}} @onClick={{this.onClick}} />`);

        assert.dom('.order-progress-tracking-number').hasText('TRK-1');
        assert.dom('.order-progress-creation-date').hasText('2026-01-02');
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(50% - 2rem);');
        assert.dom(this.element).includesText('Pickup Street').includesText('Dropoff Street').includesText('10:30').includesText('Next Stop');
        assert.dom('.order-progress-card-footer .text-green-900').exists('an assigned driver renders green');
        assert.dom(this.element).doesNotIncludeText('Stale GPS');

        await click('.order-progress-card');
        assert.strictEqual(clicked[0], this.order);

        await render(hbs`<OrderProgressCard @order={{this.order}} />`);
        await click('.order-progress-card');
        assert.strictEqual(clicked.length, 1, 'no handler, no call');
    });

    test('multi-drop, unassigned and tracker insight variants', async function (assert) {
        this.set(
            'order',
            order({
                has_driver_assigned: false,
                payload: { isMultiDrop: true, firstWaypoint: { address: 'First Stop' }, lastWaypoint: { address: 'Last Stop' } },
                tracker_data: { progress: {}, insights: { is_location_stale: true } },
            })
        );
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);
        assert.dom(this.element).includesText('First Stop').includesText('Last Stop').includesText('Stale GPS');
        assert.dom('.order-progress-card-footer .text-yellow-900').exists('no driver renders yellow');

        this.set('order', order({ tracker_data: { progress: {}, fallback_provider: 'osrm' } }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);
        assert.dom(this.element).includesText('Fallback ETA');

        this.set('order', order({ tracker_data: { progress: {}, confidence: 'low' } }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);
        assert.dom(this.element).includesText('Low ETA Confidence');

        this.set('order', order({ tracker_data: { progress: {}, confidence: 'high' } }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);
        assert.dom(this.element).doesNotIncludeText('Low ETA Confidence');
    });

    test('tracker data is loaded once for a new order without it, and failures are reported', async function (assert) {
        const loaded = [];
        const calls = [];
        this.set('onTrackerDataLoaded', (row) => loaded.push(row));
        this.set('order', order({ isNew: true, tracker_data: null, loadTrackerData: async (params, options) => calls.push([params, options]) }));

        await render(hbs`<OrderProgressCard @order={{this.order}} @onTrackerDataLoaded={{this.onTrackerDataLoaded}} />`);

        assert.deepEqual(calls, [[{}, { fromCache: true, expirationInterval: 20, expirationIntervalUnit: 'minute' }]]);
        assert.deepEqual(loaded, [this.order]);

        this.set(
            'order',
            order({
                isNew: true,
                tracker_data: null,
                loadTrackerData: async () => {
                    throw new Error('offline');
                },
            })
        );
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);

        assert.strictEqual(this.errors[0].message, 'offline');

        this.set('order', order({ isNew: true, tracker_data: null, loadTrackerData: async () => calls.push('again') }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);

        assert.strictEqual(calls.length, 2, 'a load without a callback still runs');
    });

    test('tracker data is not loaded for persisted orders, orders that already have it, or no order at all', async function (assert) {
        const calls = [];
        this.set('order', order({ isNew: false, tracker_data: null, loadTrackerData: async () => calls.push('persisted') }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);

        this.set('order', order({ isNew: true, loadTrackerData: async () => calls.push('has data') }));
        await render(hbs`<OrderProgressCard @order={{this.order}} />`);

        await render(hbs`<OrderProgressCard />`);

        assert.deepEqual(calls, []);
        assert.dom('.order-progress-card').exists('a card renders even without an order');
    });
});
