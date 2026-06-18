import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Controller | connectivity/devices/index/details/events', function (hooks) {
    setupTest(hooks);

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/events');
        assert.ok(controller);
    });

    test('it namespaces query params away from the parent devices index', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/devices/index/details/events');

        assert.deepEqual(controller.queryParams, [
            'events_page',
            'events_limit',
            'events_sort',
            'events_query',
            'events_event_type',
            'events_severity',
            'events_processed',
            'events_occurred_at',
            'events_created_at',
        ]);
    });
});
