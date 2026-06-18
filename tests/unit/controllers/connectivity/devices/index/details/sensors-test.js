import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/devices/index/details/sensors', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/sensors');
        assert.ok(controller);
    });

    test('it namespaces query params away from the parent devices index', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/sensors');

        assert.deepEqual(controller.queryParams, ['sensors_page', 'sensors_limit', 'sensors_sort', 'sensors_query', 'sensors_status', 'sensors_type', 'sensors_last_reading_at']);
    });
});
