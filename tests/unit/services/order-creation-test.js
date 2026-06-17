import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { SERVICE_QUOTE_REFRESH_REQUESTED } from '@fleetbase/fleetops-engine/services/order-creation';

module('Unit | Service | order-creation', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:order-creation');
        assert.ok(service);
    });

    test('requestServiceQuoteRefresh triggers an event payload', function (assert) {
        assert.expect(3);

        const service = this.owner.lookup('service:order-creation');
        const order = { id: 'order-1' };

        service.on(SERVICE_QUOTE_REFRESH_REQUESTED, (event) => {
            assert.strictEqual(event.reason, 'entity.added');
            assert.strictEqual(event.order, order);
        });

        service.requestServiceQuoteRefresh('entity.added', order);

        assert.ok(true, 'event was triggered');
    });
});
