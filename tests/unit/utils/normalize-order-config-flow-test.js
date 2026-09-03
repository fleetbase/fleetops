import { module, test } from 'qunit';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from '@fleetbase/fleetops-engine/utils/normalize-order-config-flow';

module('Unit | Utility | normalize-order-config-flow', function () {
    test('it preserves keyed flow graph configs', function (assert) {
        const incomingFlow = {
            created: {
                code: 'created',
                activities: ['started'],
            },
            started: {
                code: 'started',
                activities: [],
            },
        };

        assert.strictEqual(normalizeOrderConfigFlow(incomingFlow), incomingFlow);
        assert.strictEqual(getOrderConfigFlowRootCode(incomingFlow), 'created');
    });

    test('it converts a flat restored flow array into a linked graph', function (assert) {
        const flow = normalizeOrderConfigFlow([
            {
                code: 'created',
                status: 'Order Created',
            },
            {
                code: 'driver_delayed',
                status: 'Driver Delayed',
            },
            {
                code: 'completed_with_delay',
                status: 'Completed With Delay',
                complete: true,
            },
        ]);

        assert.deepEqual(Object.keys(flow), ['created', 'driver_delayed', 'completed_with_delay']);
        assert.deepEqual(flow.created.activities, ['driver_delayed']);
        assert.deepEqual(flow.driver_delayed.activities, ['completed_with_delay']);
        assert.deepEqual(flow.completed_with_delay.activities, []);
    });

    test('it keeps explicit child links on flat activities', function (assert) {
        const flow = normalizeOrderConfigFlow([
            {
                code: 'created',
                activities: ['accepted', 'canceled'],
            },
            {
                code: 'accepted',
            },
            {
                code: 'canceled',
            },
        ]);

        assert.deepEqual(flow.created.activities, ['accepted', 'canceled']);
    });

    test('it ignores malformed flat flow entries', function (assert) {
        const flow = normalizeOrderConfigFlow([
            null,
            'created',
            {
                status: 'Missing Code',
            },
            {
                code: 'created',
            },
        ]);

        assert.deepEqual(Object.keys(flow), ['created']);
    });

    test('it falls back to an empty graph for missing or scalar flows', function (assert) {
        assert.deepEqual(normalizeOrderConfigFlow(), {});
        assert.deepEqual(normalizeOrderConfigFlow(null), {});
        assert.deepEqual(normalizeOrderConfigFlow('created'), {});
        assert.strictEqual(getOrderConfigFlowRootCode(), undefined);
        assert.strictEqual(getOrderConfigFlowRootCode({ started: {} }), 'started', 'without a created activity the first key is the root');
    });

    test('it links flat activities by key when they carry no code', function (assert) {
        const flow = normalizeOrderConfigFlow([{ code: 'created' }, { key: 'accepted' }]);

        assert.deepEqual(Object.keys(flow), ['created', 'accepted']);
        assert.deepEqual(flow.created, { code: 'created', key: 'created', activities: ['accepted'] }, 'a missing key falls back to the code');
        assert.deepEqual(flow.accepted, { code: 'accepted', key: 'accepted', activities: [] });
    });
});
