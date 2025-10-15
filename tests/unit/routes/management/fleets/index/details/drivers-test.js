import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | management/fleets/index/details/drivers', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:management/fleets/index/details/drivers');
        assert.ok(route);
    });
});
