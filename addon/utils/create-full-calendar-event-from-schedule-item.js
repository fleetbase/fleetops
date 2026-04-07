import { get } from '@ember/object';
import toCalendarDate from './to-calendar-date';

/**
 * createFullCalendarEventFromScheduleItem
 *
 * Transforms a ScheduleItem Ember Data record into a calendar event object for
 * @event-calendar/core.  Used to render driver shift windows as background
 * blocks on the resource timeline.
 *
 * @event-calendar/core has no timezone support — it reads the browser-local
 * fields of any Date passed as `start`/`end` and positions the event at that
 * wall-clock time.  To display shifts in the company timezone regardless of
 * the browser's timezone, we pass a "fake local" Date whose local fields equal
 * the company wall-clock time.  See `to-calendar-date.js` for details.
 *
 * @param {Model}  scheduleItem  A `schedule-item` Ember Data record.
 * @param {Model}  driver        The associated `driver` Ember Data record.
 * @param {string} timezone      IANA timezone string for the company, e.g. 'Asia/Singapore'.
 * @param {Object} [overrides]   Optional calendar property overrides (e.g. display, backgroundColor).
 * @returns {Object}  Calendar event object.
 */
export default function createFullCalendarEventFromScheduleItem(scheduleItem, driver, timezone, overrides = {}) {
    const driverName = get(driver, 'name') ?? 'Unknown Driver';
    const title = get(scheduleItem, 'title') || `${driverName} — Shift`;

    const startRaw = get(scheduleItem, 'start_at');
    const endRaw = get(scheduleItem, 'end_at');

    // Convert the UTC start/end to wall-clock Dates in the company timezone.
    const start = startRaw ? toCalendarDate(new Date(startRaw), timezone) : null;
    const end = endRaw ? toCalendarDate(new Date(endRaw), timezone) : null;

    return {
        id: `shift-${get(scheduleItem, 'id')}`,
        resourceId: get(scheduleItem, 'assignee_uuid') ?? get(driver, 'id'),
        title,
        start,
        end,
        display: 'background',
        backgroundColor: 'rgba(99, 102, 241, 0.08)',
        borderColor: 'rgba(99, 102, 241, 0.25)',
        extendedProps: {
            scheduleItem,
            driver,
            type: 'shift',
        },
        ...overrides,
    };
}
