import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | order-list-overlay', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:order-list-overlay');
        assert.ok(service);
    });

    test('activeOrdersCount reflects loaded active orders', function (assert) {
        let service = this.owner.lookup('service:order-list-overlay');

        service.activeOrders = [{ public_id: 'order_1' }, { public_id: 'order_2' }];

        assert.strictEqual(service.activeOrdersCount, 2);
    });
});
