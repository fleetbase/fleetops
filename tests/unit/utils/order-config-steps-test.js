import { module, test } from 'qunit';
import orderConfigSteps from 'dummy/utils/order-config-steps';

const step = (code, status, activities = [], extra = {}) => ({ code, key: code, status, activities, ...extra });

module('Unit | Utility | order-config-steps', function () {
    test('it orders steps from the first one, breadth first, marking branches', function (assert) {
        const config = {
            flow: {
                completed: step('completed', 'Trip completed', [], { complete: true }),
                no_show: step('no_show', 'No show'),
                arrived: step('arrived', 'Arrived', ['in_progress', 'no_show']),
                in_progress: step('in_progress', 'In progress', ['completed']),
                created: step('created', 'Requested', ['arrived']),
            },
        };
        const steps = orderConfigSteps(config);

        assert.deepEqual(
            steps.map((s) => s.code),
            ['created', 'arrived', 'in_progress', 'no_show', 'completed']
        );
        assert.strictEqual(steps.find((s) => s.code === 'no_show').branchFrom, 'arrived');
        assert.strictEqual(steps.find((s) => s.code === 'in_progress').branchFrom, null);
    });

    test('it starts at the lifecycle initial step and lists unreachable steps last', function (assert) {
        const config = {
            meta: { lifecycle: { initial: 'booked' } },
            flow: { orphan: step('orphan', 'Orphan'), booked: step('booked', 'Booked', ['done']), done: step('done', 'Done') },
        };

        assert.deepEqual(
            orderConfigSteps(config).map((s) => s.code),
            ['booked', 'done', 'orphan']
        );
    });
});
