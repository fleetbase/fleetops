import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/card', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders order identity, status, type, and route stops', async function (assert) {
        this.set('order', {
            tracking_number: { tracking_number: 'TRK-1001' },
            status: 'dispatched',
            type: 'delivery',
            payload: {
                pickup: { address: '1 Pickup Street' },
                dropoff: { address: '99 Dropoff Avenue' },
            },
        });

        await render(hbs`<Order::Card @order={{this.order}} />`);

        assert.dom('.order-card-title').hasText('TRK-1001');
        assert.dom('.order-card-subtitle').hasText('Delivery');
        assert.dom('.order-card').includesText('1 Pickup Street');
        assert.dom('.order-card').includesText('99 Dropoff Avenue');
        assert.dom('.order-card-progress').hasClass('is-active');
    });

    test('it renders public id fallback and waypoint summary', async function (assert) {
        this.set('order', {
            public_id: 'order_public_1',
            status: 'pending',
            payload: {
                pickup: { address: 'Origin' },
                waypoints: [{ address: 'Stop A' }, { place: { address: 'Stop B' } }],
                dropoff: { address: 'Destination' },
            },
        });

        await render(hbs`<Order::Card @order={{this.order}} />`);

        assert.dom('.order-card-title').hasText('order_public_1');
        assert.dom('.order-card').includesText('Waypoints');
        assert.dom('.order-card').includesText('2 additional stops');
        assert.dom('.order-card-progress').hasClass('is-pending');
    });

    test('it renders an empty route state without payload stops', async function (assert) {
        this.set('order', { id: 'order_empty', status: 'completed' });

        await render(hbs`<Order::Card @order={{this.order}} />`);

        assert.dom('.order-card-empty-route').hasText('No route details available.');
        assert.dom('.order-card-progress').hasClass('is-complete');
    });

    test('it renders optional meta from existing order data', async function (assert) {
        this.set('order', {
            id: 'order_meta',
            scheduled_at: '2026-06-12T10:00:00Z',
            driver_assigned: { name: 'Ron Driver' },
            vehicle_assigned: { display_name: 'Truck 45' },
            customer: { name: 'Acme Corp' },
            payload: {
                pickup: { address: 'Origin' },
                dropoff: { address: 'Destination' },
            },
        });

        await render(hbs`<Order::Card @order={{this.order}} @showCustomer={{true}} />`);

        assert.dom('.order-card-meta').includesText('Ron Driver');
        assert.dom('.order-card-meta').includesText('Truck 45');
        assert.dom('.order-card-meta').includesText('Acme Corp');
    });

    test('it calls onClick with the order when interactive', async function (assert) {
        assert.expect(2);

        const order = {
            id: 'order_click',
            payload: {
                pickup: { address: 'Origin' },
                dropoff: { address: 'Destination' },
            },
        };

        this.set('order', order);
        this.set('viewOrder', (clickedOrder) => {
            assert.strictEqual(clickedOrder, order);
        });

        await render(hbs`<Order::Card @order={{this.order}} @interactive={{true}} @onClick={{this.viewOrder}} />`);

        assert.dom('.order-card').hasAttribute('role', 'button');
        await click('.order-card');
    });

    test('it renders selectable selected and disabled states', async function (assert) {
        this.set('order', { id: 'order_selected' });

        await render(hbs`<Order::Card @order={{this.order}} @selectable={{true}} @selected={{true}} @disabled={{true}} />`);

        assert.dom('.order-card').hasClass('is-selectable');
        assert.dom('.order-card').hasClass('is-selected');
        assert.dom('.order-card').hasClass('is-disabled');
        assert.dom('.order-card-checkbox').isChecked();
        assert.dom('.order-card-checkbox').isDisabled();
    });
});
