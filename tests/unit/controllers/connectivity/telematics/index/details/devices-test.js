import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/telematics/index/details/devices', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/telematics/index/details/devices');
        assert.ok(controller);
    });
});
