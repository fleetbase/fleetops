import { module, test } from 'qunit';
import { buildRrule, parseRrule } from 'dummy/utils/recurring-rrule';

module('Unit | Utility | recurring-rrule', function () {
    test('it builds weekly recurring rules with weekdays and until', function (assert) {
        const rrule = buildRrule({
            frequency: 'weekly',
            interval: 2,
            weekdays: ['MO', 'WE'],
            until: '2026-05-31T00:00:00.000Z',
        });

        assert.strictEqual(rrule, 'FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE;UNTIL=20260531T000000Z');
    });

    test('it parses recurring rule parts into editable state', function (assert) {
        const parsed = parseRrule('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=15');

        assert.strictEqual(parsed.frequency, 'monthly');
        assert.strictEqual(parsed.interval, 1);
        assert.deepEqual(parsed.weekdays, []);
        assert.strictEqual(parsed.monthday, 15);
    });
});
