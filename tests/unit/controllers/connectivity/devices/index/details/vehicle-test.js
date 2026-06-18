import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/devices/index/details/vehicle', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/vehicle');
        assert.ok(controller);
    });
});
