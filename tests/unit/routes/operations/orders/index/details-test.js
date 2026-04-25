import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | operations/orders/index/details', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:operations/orders/index/details');
        assert.ok(route);
    });

    test('willTransition does not cleanup when switching inside order details tabs', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/details');

        let stopCalled = false;
        let removeCalled = false;
        let showCalled = false;

        route.orderSocketEvents = {
            stop() {
                stopCalled = true;
            },
        };
        route.leafletMapManager = {
            removeRoutingControl() {
                removeCalled = true;
            },
        };
        route.universe = {
            sidebarContext: {
                show() {
                    showCalled = true;
                },
            },
        };
        route.controllerFor = () => ({
            model: { id: 'order_1' },
            routingControl: { id: 'rc_1' },
        });

        route.willTransition({
            from: { name: 'console.fleet-ops.operations.orders.index.details.virtual' },
            to: { name: 'console.fleet-ops.operations.orders.index.details.index' },
        });

        assert.false(stopCalled);
        assert.false(removeCalled);
        assert.false(showCalled);
    });

    test('willTransition cleans up when leaving the order details route tree', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/details');

        let stopCalled = false;
        let removeCalled = false;
        let showCalled = false;

        route.orderSocketEvents = {
            stop() {
                stopCalled = true;
            },
        };
        route.leafletMapManager = {
            removeRoutingControl() {
                removeCalled = true;
            },
        };
        route.universe = {
            sidebarContext: {
                show() {
                    showCalled = true;
                },
            },
        };
        route.controllerFor = () => ({
            model: { id: 'order_1' },
            routingControl: { id: 'rc_1' },
        });

        route.willTransition({
            from: { name: 'console.fleet-ops.operations.orders.index.details.index' },
            to: { name: 'console.fleet-ops.operations.orders.index' },
        });

        assert.true(stopCalled);
        assert.true(removeCalled);
        assert.true(showCalled);
    });
});
