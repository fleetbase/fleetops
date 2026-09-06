import { module, test } from 'qunit';
import toCalendarDate from '@fleetbase/fleetops-engine/utils/to-calendar-date';

module('Unit | Utility | to-calendar-date', function () {
    test('it returns a date whose local fields equal the wall-clock time in the timezone', function (assert) {
        const singapore = toCalendarDate(new Date('2026-04-06T14:30:15Z'), 'Asia/Singapore');
        assert.deepEqual(
            [singapore.getFullYear(), singapore.getMonth(), singapore.getDate(), singapore.getHours(), singapore.getMinutes(), singapore.getSeconds()],
            [2026, 3, 6, 22, 30, 15]
        );

        const midnight = toCalendarDate('2026-04-06T16:00:00Z', 'Asia/Singapore');
        assert.strictEqual(midnight.getHours(), 0, 'a string input is parsed and midnight is hour zero');
        assert.strictEqual(midnight.getDate(), 7);
    });

    test('it returns the input date unchanged without a timezone or with an invalid one', function (assert) {
        const date = new Date('2026-04-06T14:30:00Z');

        assert.strictEqual(toCalendarDate(date), date);
        assert.strictEqual(toCalendarDate(date, 'Not/AZone'), date, 'an unknown timezone falls back rather than throwing');
    });
});
