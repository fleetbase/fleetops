import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, doubleClick, render, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function order(overrides = {}) {
    return {
        tracking: 'TRK-1',
        status: 'active',
        tracker_data: { progress: { percentage: 40, completed_stops: 1 } },
        payload: { pickup: { address: 'Pickup Street' }, dropoff: { address: 'Dropoff Street' } },
        customer: { name: 'Acme', phone: '+65 1' },
        driver_assigned: { name: 'Ada', phone: '+65 2', online: true },
        ...overrides,
    };
}

module('Integration | Component | order-list-overlay/order', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the order summary, addresses, customer and driver', async function (assert) {
        this.set('order', order());

        await render(hbs`<OrderListOverlay::Order @order={{this.order}} @index={{3}} @isSelected={{true}}>selected block</OrderListOverlay::Order>`);

        assert.dom('.order-listings-row-container').hasClass('selected');
        assert.dom('.order-listing-row-index').hasText('3');
        assert.dom(this.element).includesText('TRK-1').includesText('Pickup Street').includesText('Dropoff Street').includesText('Acme').includesText('Ada').includesText('selected block');
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(40% - 2rem);');
        assert.dom('.resource-assigned-photo svg[data-icon="circle"]').hasClass('text-green-500');
        assert.dom(this.element).doesNotIncludeText('Stale GPS').doesNotIncludeText('Fallback ETA').doesNotIncludeText('Low ETA Confidence');
    });

    test('multi-drop orders show the first and last waypoints and missing people show placeholders', async function (assert) {
        this.set('order', order({ payload: { isMultiDrop: true, firstWaypoint: { address: 'First Stop' }, lastWaypoint: { address: 'Last Stop' } }, customer: null, driver_assigned: null }));

        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);

        assert.dom(this.element).includesText('First Stop').includesText('Last Stop').includesText('No Customer').includesText('No Driver').includesText('No Phone');
        assert.dom('.resource-assigned-photo svg[data-icon="circle"]').hasClass('text-yellow-200');
    });

    test('tracker insights surface as warning badges', async function (assert) {
        this.set('order', order({ tracker_data: { progress: {}, insights: { is_location_stale: true } } }));
        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);
        assert.dom(this.element).includesText('Stale GPS');

        this.set('order', order({ tracker_data: { progress: {}, fallback_provider: 'osrm' } }));
        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);
        assert.dom(this.element).includesText('Fallback ETA');

        this.set('order', order({ tracker_data: { progress: {}, confidence: 'low' } }));
        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);
        assert.dom(this.element).includesText('Low ETA Confidence');

        this.set('order', order({ tracker_data: { progress: {}, confidence: 'high' } }));
        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);
        assert.dom(this.element).doesNotIncludeText('Low ETA Confidence');
    });

    test('row events reach the callbacks with the order, except clicks on action buttons', async function (assert) {
        const events = [];
        this.set('order', order());
        this.set('onClick', (row) => events.push(['click', row]));
        this.set('onDoubleClick', (row) => events.push(['dblclick', row]));
        this.set('onMouseEnter', (row) => events.push(['enter', row]));
        this.set('onMouseLeave', (row) => events.push(['leave', row]));

        await render(hbs`
            <OrderListOverlay::Order @order={{this.order}} @onClick={{this.onClick}} @onDoubleClick={{this.onDoubleClick}} @onMouseEnter={{this.onMouseEnter}} @onMouseLeave={{this.onMouseLeave}}>
                <span class="order-listing-action-button" data-test-action>act</span>
            </OrderListOverlay::Order>
        `);

        await triggerEvent('.order-listings-row-container', 'mouseenter');
        await click('.order-listings-row-container');
        await doubleClick('.order-listings-row-container');
        await triggerEvent('.order-listings-row-container', 'mouseleave');
        await click('[data-test-action]');

        assert.deepEqual(
            events.map(([name, row]) => [name, row === this.order]),
            [
                ['enter', true],
                ['click', true],
                ['click', true],
                ['click', true],
                ['dblclick', true],
                ['leave', true],
            ],
            'a double click also fires two clicks; the action button click is swallowed'
        );
    });

    test('row events without callbacks are no-ops', async function (assert) {
        this.set('order', order());
        await render(hbs`<OrderListOverlay::Order @order={{this.order}} />`);

        await triggerEvent('.order-listings-row-container', 'mouseenter');
        await click('.order-listings-row-container');
        await doubleClick('.order-listings-row-container');
        await triggerEvent('.order-listings-row-container', 'mouseleave');

        assert.dom(this.element).includesText('TRK-1');
    });
});
