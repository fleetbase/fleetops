import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/telematics/index/details/devices', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/telematics/index/details/devices');
        assert.ok(route);
    });

    test('device filter query params refresh the model', function (assert) {
        const route = this.owner.lookup('route:connectivity/telematics/index/details/devices');

        assert.deepEqual(route.queryParams.vehicle, { refreshModel: true }, 'vehicle filter refreshes devices');
        assert.deepEqual(route.queryParams.connection_status, { refreshModel: true }, 'connection filter refreshes devices');
        assert.deepEqual(route.queryParams.last_online_at, { refreshModel: true }, 'last seen date filter refreshes devices');
        assert.deepEqual(route.queryParams.updated_at, { refreshModel: true }, 'updated date filter refreshes devices');
    });
});
