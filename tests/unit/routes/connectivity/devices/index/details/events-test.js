import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/devices/index/details/events', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/events');
        assert.ok(route);
    });

    test('it maps namespaced query params to device-event API params', async function (assert) {
        let route = this.owner.lookup('route:connectivity/devices/index/details/events');
        let query;

        route.modelFor = () => ({ id: 'device-1' });
        route.store = {
            query(modelName, params) {
                query = { modelName, params };
                return [];
            },
        };

        await route.model({
            events_page: 3,
            events_limit: 50,
            events_sort: '-created_at',
            events_query: 'fault',
            events_event_type: 'diagnostic',
            events_severity: 'warning',
            events_processed: 'unprocessed',
            events_occurred_at: '2026-06-18',
            events_created_at: '2026-06-17',
        });

        assert.deepEqual(query, {
            modelName: 'device-event',
            params: {
                page: 3,
                limit: 50,
                sort: '-created_at',
                query: 'fault',
                event_type: 'diagnostic',
                severity: 'warning',
                processed: 'unprocessed',
                occurred_at: '2026-06-18',
                created_at: '2026-06-17',
                device_uuid: 'device-1',
            },
        });
    });
});
