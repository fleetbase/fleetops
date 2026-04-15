/**
 * toCalendarDate
 *
 * Converts a UTC Date into a "fake local" Date whose **local** fields
 * (getFullYear, getMonth, getDate, getHours, getMinutes, getSeconds) equal the
 * wall-clock time in the given IANA timezone.
 *
 * Why this is needed
 * ------------------
 * @event-calendar/core has no timezone support (see maintainer comment:
 * https://github.com/vkurko/calendar/issues/576).  When the library ingests an
 * event it reads the Date's **local** fields and stores them as UTC wall-clock
 * values.  This means the event is positioned at whatever the browser's local
 * time is for the given UTC instant — which is wrong when the company timezone
 * differs from the browser timezone.
 *
 * By constructing a Date whose local fields already equal the company-local
 * wall-clock time, the library will position the event at the correct time
 * regardless of the browser's timezone.
 *
 * Example
 * -------
 *   UTC instant:        2026-04-06T14:30:00Z
 *   Company timezone:   Asia/Singapore (UTC+8)
 *   Wall-clock time:    2026-04-06 22:30 SGT
 *
 *   toCalendarDate(new Date('2026-04-06T14:30:00Z'), 'Asia/Singapore')
 *   → new Date(2026, 3, 6, 22, 30, 0)   ← local fields = 22:30 on Apr 6
 *
 * The calendar reads the local fields (22:30, Apr 6) and positions the event
 * there — correct regardless of whether the browser is UTC, UTC+8, or anything
 * else.
 *
 * @param {Date|string|number} utcDate  Any value that `new Date()` accepts.
 * @param {string}             timezone IANA timezone string, e.g. 'Asia/Singapore'.
 * @returns {Date}  A Date whose local fields equal the wall-clock time in `timezone`.
 */
export default function toCalendarDate(utcDate, timezone) {
    const date = utcDate instanceof Date ? utcDate : new Date(utcDate);

    if (!timezone) {
        // No timezone provided — return the date unchanged.
        return date;
    }

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).formatToParts(date);

        const get = (type) => parseInt(parts.find((p) => p.type === type)?.value ?? '0', 10);

        // hour12: false can return 24 for midnight — normalise to 0.
        const hour = get('hour') % 24;

        // Construct a Date whose LOCAL fields equal the company wall-clock time.
        // The UTC fields of this Date are irrelevant; the library only reads local fields.
        return new Date(get('year'), get('month') - 1, get('day'), hour, get('minute'), get('second'));
    } catch {
        // Intl unavailable or invalid timezone — return the original date unchanged.
        return date;
    }
}
