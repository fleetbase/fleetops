import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | operations/orders/index/details', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:operations/orders/index/details');
        assert.ok(route);
    });

    function stubController(route) {
        const calls = [];
        route.controllerFor = (name) => {
            calls.push(['controllerFor', name]);
            return {
                teardownRealtime: () => calls.push(['teardownRealtime']),
                teardownRoutingControls: () => calls.push(['teardownRoutingControls']),
            };
        };
        return calls;
    }

    test('willTransition does not clean up when switching inside the order details tabs', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/details');
        const calls = stubController(route);

        const result = route.willTransition({
            from: { name: 'console.fleet-ops.operations.orders.index.details.virtual' },
            to: { name: 'console.fleet-ops.operations.orders.index.details.index' },
        });

        assert.true(result);
        assert.deepEqual(calls, []);
    });

    test('willTransition does not clean up on a refresh of the same details route', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/details');
        const calls = stubController(route);

        route.willTransition({
            from: { name: 'console.fleet-ops.operations.orders.index.details.index' },
            to: { name: 'console.fleet-ops.operations.orders.index.details.index' },
        });
        route.willTransition({ from: null, to: { name: 'console.fleet-ops.operations.orders.index' } });

        assert.deepEqual(calls, [], 'neither a refresh nor an entry transition tears anything down');
    });

    test('willTransition tears down realtime and routing controls when leaving the order details route tree', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/details');
        const calls = stubController(route);

        const result = route.willTransition({
            from: { name: 'console.fleet-ops.operations.orders.index.details.index' },
            to: { name: 'console.fleet-ops.operations.orders.index' },
        });

        assert.true(result);
        assert.deepEqual(calls, [['controllerFor', 'operations.orders.index.details'], ['teardownRealtime'], ['teardownRoutingControls']]);
    });
});
