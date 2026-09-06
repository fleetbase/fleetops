import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | operations/orders/index/details', function (hooks) {
    setupTest(hooks);

    test('it exists and injects the orders index controller', function (assert) {
        // `@controller('operations.orders.index')` resolves lazily; a unit test has to instantiate the parent first.
        const index = this.owner.lookup('controller:operations/orders/index');
        const controller = this.owner.lookup('controller:operations/orders/index/details');

        assert.ok(controller);
        assert.strictEqual(controller.index, index);
    });
});
