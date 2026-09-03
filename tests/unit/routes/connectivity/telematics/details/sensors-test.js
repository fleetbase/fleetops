import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/telematics/details/sensors', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/telematics/details/sensors');
        assert.ok(route);
    });
});
