import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | maintenance/work-orders', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:maintenance/work-orders');
        assert.ok(route);
    });
});
