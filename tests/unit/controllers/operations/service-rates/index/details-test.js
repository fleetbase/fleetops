import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | operations/service-rates/index/details', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:operations/service-rates/index/details');
        assert.ok(controller);
    });
});
