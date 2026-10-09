import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

module('Unit | Route | operations/orders/index/new', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        // The engine host provides this; the dummy app does not.
        this.owner.register('service:hostRouter', class extends Service {});
    });

    test('it keeps the draft order when the route refreshes', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/new');
        let resets = 0;

        route.resetController({ reset: () => resets++ }, false);

        assert.strictEqual(resets, 0);
    });

    test('it resets the draft order when leaving the route', function (assert) {
        const route = this.owner.lookup('route:operations/orders/index/new');
        let resets = 0;

        route.resetController({ reset: () => resets++ }, true);

        assert.strictEqual(resets, 1);
    });
});
