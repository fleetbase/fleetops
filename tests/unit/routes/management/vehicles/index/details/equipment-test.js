import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | management/vehicles/index/details/equipment', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:management/vehicles/index/details/equipment');
        assert.ok(route);
    });
});
