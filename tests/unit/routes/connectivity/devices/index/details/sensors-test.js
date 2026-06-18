import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/devices/index/details/sensors', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/sensors');
        assert.ok(route);
    });

    test('it maps namespaced query params to sensor API params', async function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/sensors');
        let query;

        route.modelFor = () => ({ id: 'device-1' });
        route.store = {
            query(modelName, params) {
                query = { modelName, params };
                return [];
            },
        };

        await route.model({
            sensors_page: 2,
            sensors_limit: 25,
            sensors_sort: '-updated_at',
            sensors_query: 'temperature',
            sensors_status: 'active',
            sensors_type: 'temperature',
            sensors_last_reading_at: '2026-06-18',
        });

        assert.deepEqual(query, {
            modelName: 'sensor',
            params: {
                page: 2,
                limit: 25,
                sort: '-updated_at',
                query: 'temperature',
                status: 'active',
                type: 'temperature',
                last_reading_at: '2026-06-18',
                device_uuid: 'device-1',
            },
        });
    });
});
