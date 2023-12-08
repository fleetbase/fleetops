import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | base-controller', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:base-controller');
        assert.ok(controller);
    });
});
