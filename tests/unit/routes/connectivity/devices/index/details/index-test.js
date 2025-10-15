import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/devices/index/details/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/index');
        assert.ok(route);
    });
});
