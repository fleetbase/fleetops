import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/telematics/index/details', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/telematics/index/details');
        assert.ok(route);
    });
});
