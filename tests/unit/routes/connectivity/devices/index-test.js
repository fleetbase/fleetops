import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/devices/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index');
        assert.ok(route);
    });

    test('device inventory filters refresh the route model', function (assert) {
        const route = this.owner.lookup('route:connectivity/devices/index');

        for (const param of ['connection_status', 'vehicle', 'provider', 'device_id', 'type', 'serial_number', 'last_online_at', 'attachment_state']) {
            assert.deepEqual(route.queryParams[param], { refreshModel: true }, `${param} refreshes the device inventory model`);
        }
    });
});
