import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/sensors/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/sensors/index');
        assert.ok(route);
    });

    test('it refreshes for every supported global sensor filter', function (assert) {
        let route = this.owner.lookup('route:connectivity/sensors/index');

        assert.deepEqual(Object.keys(route.queryParams), [
            'page',
            'limit',
            'sort',
            'query',
            'telematic',
            'device',
            'type',
            'status',
            'serial_number',
            'imei',
            'last_reading_at',
            'created_at',
            'updated_at',
        ]);
    });

    test('it queries sensors with the provided params', function (assert) {
        let route = this.owner.lookup('route:connectivity/sensors/index');
        let query;

        route.store = {
            query(modelName, params) {
                query = { modelName, params };
                return [];
            },
        };

        route.model({
            query: 'temp',
            telematic: 'telematic_1',
            device: 'device_1',
            type: 'temperature',
            status: 'active',
            serial_number: 'SN-1',
            imei: 'IMEI-1',
            last_reading_at: '2026-06-18',
            created_at: '2026-06-17',
            updated_at: '2026-06-19',
        });

        assert.deepEqual(query, {
            modelName: 'sensor',
            params: {
                query: 'temp',
                telematic: 'telematic_1',
                device: 'device_1',
                type: 'temperature',
                status: 'active',
                serial_number: 'SN-1',
                imei: 'IMEI-1',
                last_reading_at: '2026-06-18',
                created_at: '2026-06-17',
                updated_at: '2026-06-19',
            },
        });
    });
});
