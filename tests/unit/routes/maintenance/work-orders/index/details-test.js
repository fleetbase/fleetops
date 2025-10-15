import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | maintenance/work-orders/index/details', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:maintenance/work-orders/index/details');
        assert.ok(route);
    });
});
