import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Route | connectivity/events/index', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/index');
        assert.ok(route);
    });

    test('it refreshes for every supported global event filter', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/index');

        assert.deepEqual(Object.keys(route.queryParams), [
            'page',
            'limit',
            'sort',
            'query',
            'telematic',
            'device',
            'event_type',
            'severity',
            'processed',
            'occurred_at',
            'created_at',
            'updated_at',
        ]);
    });

    test('it queries device events with the provided params', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/index');
        let query;

        route.store = {
            query(modelName, params) {
                query = { modelName, params };
                return [];
            },
        };

        route.model({
            query: 'fault',
            telematic: 'telematic_1',
            device: 'device_1',
            event_type: 'diagnostic',
            severity: 'warning',
            processed: 'unprocessed',
            occurred_at: '2026-06-18',
            created_at: '2026-06-17',
            updated_at: '2026-06-19',
        });

        assert.deepEqual(query, {
            modelName: 'device-event',
            params: {
                query: 'fault',
                telematic: 'telematic_1',
                device: 'device_1',
                event_type: 'diagnostic',
                severity: 'warning',
                processed: 'unprocessed',
                occurred_at: '2026-06-18',
                created_at: '2026-06-17',
                updated_at: '2026-06-19',
            },
        });
    });
});
