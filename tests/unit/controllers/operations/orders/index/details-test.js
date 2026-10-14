import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Controller from '@ember/controller';
import Service from '@ember/service';

module('Unit | Controller | operations/orders/index/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        // The engine host provides these; the dummy app does not.
        this.owner.register('service:hostRouter', class extends Service {});
        this.owner.register('controller:operations.orders.index', class extends Controller {});
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:operations/orders/index/details');
        assert.ok(controller);
    });

    test('the overview tab links to the current order explicitly', function (assert) {
        const controller = this.owner.lookup('controller:operations/orders/index/details');
        controller.model = { public_id: 'order_abc123' };

        const [overview] = controller.tabs;

        assert.strictEqual(overview.route, 'operations.orders.index.details.index');
        assert.strictEqual(overview.model, 'order_abc123');
    });

    test('refreshDetails refreshes only the order details route', async function (assert) {
        const controller = this.owner.lookup('controller:operations/orders/index/details');
        const route = this.owner.lookup('route:operations/orders/index/details');
        let refreshed = 0;
        route.refresh = () => {
            refreshed++;
            return Promise.resolve();
        };
        controller.hostRouter.refresh = () => assert.ok(false, 'the whole route tree is not refreshed');

        await controller.refreshDetails();

        assert.strictEqual(refreshed, 1);
    });

    test('refreshDetails ignores a refresh superseded by another transition', async function (assert) {
        const controller = this.owner.lookup('controller:operations/orders/index/details');
        const route = this.owner.lookup('route:operations/orders/index/details');
        const aborted = new Error('TransitionAborted');
        aborted.name = 'TransitionAborted';
        route.refresh = () => Promise.reject(aborted);

        await controller.refreshDetails();

        assert.ok(true, 'the aborted transition does not reject');
    });

    test('refreshDetails rethrows other errors', async function (assert) {
        const controller = this.owner.lookup('controller:operations/orders/index/details');
        const route = this.owner.lookup('route:operations/orders/index/details');
        route.refresh = () => Promise.reject(new Error('boom'));

        await assert.rejects(controller.refreshDetails(), /boom/);
    });
});
