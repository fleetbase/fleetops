import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/telematics/details/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/telematics/details/index');
        assert.ok(route);
    });
});
