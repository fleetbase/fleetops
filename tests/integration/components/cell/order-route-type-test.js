import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled, triggerEvent, waitUntil } from '@ember/test-helpers';
import { TrackedObject } from 'tracked-built-ins';
import { hbs } from 'ember-cli-htmlbars';
import { setComponentTemplate } from '@ember/component';
import templateOnly from '@ember/component/template-only';
import { defer } from 'rsvp';

// A plain object would not notify Glimmer when loadPayload replaces the payload; an Ember Data
// order would, so fixtures that load are tracked objects.
function makeOrder(attrs) {
    return new TrackedObject({ loads: 0, ...attrs });
}

const TRIGGER = '.orders-route-type-trigger';
const BADGE = '.orders-route-type-badge';

// RouteList is a large component with its own suite; the cell only needs to know it was asked to render.
const RouteListStub = setComponentTemplate(hbs`<div class="route-list-stub" data-collapsible={{if @isCollapsible "yes" "no"}}>{{@order.public_id}}</div>`, templateOnly());

module('Integration | Component | cell/order-route-type', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('component:route-list', RouteListStub);
    });

    test('a plain pickup and dropoff order renders a gray badge without a preview', async function (assert) {
        this.set('order', { public_id: 'order_1', payload: { pickup_uuid: 'p', dropoff_uuid: 'd', waypoints_count: 0 } });

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);

        assert.dom(BADGE).hasText('Pickup & Dropoff').hasClass('import-preview-badge--gray');
        assert.dom(`${BADGE} svg`).exists();
        assert.dom(TRIGGER).doesNotExist();
    });

    test('an order without a payload still renders the plain badge', async function (assert) {
        this.set('order', { public_id: 'order_0' });

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);

        assert.dom(BADGE).hasText('Pickup & Dropoff');
    });

    test('a multi-stop order counts its waypoints and previews them once loaded', async function (assert) {
        const order = makeOrder({
            public_id: 'order_2',
            payload: { waypoints_count: 3, waypoints: [] },
            loadPayload() {
                this.loads++;
                this.payload = { waypoints_count: 3, waypoints: [{}, {}, {}] };
                return Promise.resolve();
            },
        });
        this.set('order', order);

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);

        assert.dom(BADGE).hasText('3 stops').hasClass('import-preview-badge--blue');
        assert.dom('svg[data-icon="route"]').exists();
        assert.dom(this.element).includesText('Loading route preview...', 'the preview waits for a hover');

        await triggerEvent(TRIGGER, 'mouseenter');

        assert.strictEqual(order.loads, 1);
        assert.dom('.route-list-stub').hasText('order_2').hasAttribute('data-collapsible', 'no');

        await triggerEvent(TRIGGER, 'focusin');

        assert.strictEqual(order.loads, 1, 'a loaded payload is not fetched again');
    });

    test('pickup and dropoff plus stops uses the indexed count when the loaded waypoints are fewer', async function (assert) {
        this.set('order', { public_id: 'order_3', hasIntermediateWaypoints: true, payload: { pickup_uuid: 'p', dropoff_uuid: 'd', waypoints_count: 2, waypoints: [{}] } });

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);

        assert.dom(BADGE).hasText('P & D + 2 Stops');
        assert.dom('.route-list-stub').exists('a loaded waypoint list renders immediately');
    });

    test('the loading state shows while the payload is in flight and only one load runs', async function (assert) {
        const deferred = defer();
        const order = makeOrder({
            public_id: 'order_4',
            payload: { waypoints_count: 2 },
            loadPayload() {
                this.loads++;
                return deferred.promise;
            },
        });
        this.set('order', order);

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        triggerEvent(TRIGGER, 'mouseenter');
        triggerEvent(TRIGGER, 'focusin');
        await waitUntil(() => this.element.textContent.includes('Loading route...'));

        assert.strictEqual(order.loads, 1, 'concurrent hover and focus share one request');

        order.payload = { waypoints_count: 2, waypoints: [{}, {}] };
        deferred.resolve();
        await settled();

        assert.dom('.route-list-stub').exists();
    });

    test('a failed load shows the error message, or a generic one when the error has none', async function (assert) {
        this.set('order', { public_id: 'order_5', payload: { waypoints_count: 2 }, loadPayload: () => Promise.reject(new Error('Route service down')) });

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        await triggerEvent(TRIGGER, 'mouseenter');

        assert.dom('.orders-route-type-preview__state--error').hasText('Route service down');

        this.set('order', { public_id: 'order_6', payload: { waypoints_count: 2 }, loadPayload: () => Promise.reject({}) });
        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        await triggerEvent(TRIGGER, 'mouseenter');

        assert.dom('.orders-route-type-preview__state--error').hasText('Unable to load route preview.');
    });

    test('an order that cannot load its payload keeps the hover hint', async function (assert) {
        this.set('order', { public_id: 'order_7', payload: { waypoints_count: 2 } });

        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        await triggerEvent(TRIGGER, 'mouseenter');

        assert.dom(this.element).includesText('Loading route preview...');
        assert.dom('.route-list-stub').doesNotExist();
    });

    test('a waypoint collection with toArray counts as loaded only when nothing is expected', async function (assert) {
        const empty = { toArray: () => [] };

        this.set('order', { public_id: 'order_8', hasIntermediateWaypoints: true, payload: { waypoints_count: 0, waypoints: empty } });
        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        assert.dom('.route-list-stub').exists('an empty but present collection with no expected stops is considered loaded');

        this.set('order', { public_id: 'order_9', payload: { waypoints_count: 2, waypoints: empty } });
        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        assert.dom('.route-list-stub').doesNotExist('expected stops are still missing');

        this.set('order', { public_id: 'order_10', hasIntermediateWaypoints: true, payload: { waypoints_count: 0, waypoints: [] } });
        await render(hbs`<Cell::OrderRouteType @row={{this.order}} />`);
        assert.dom('.route-list-stub').exists('an empty array with nothing expected is loaded too');
    });
});
