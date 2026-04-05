import { get } from '@ember/object';

/**
 * createFullCalendarEventFromScheduleItem
 *
 * Transforms a ScheduleItem Ember Data record into a FullCalendar event object.
 * Used to render driver shift windows as background blocks on the resource timeline.
 *
 * @param {Model}  scheduleItem  - A `schedule-item` Ember Data record
 * @param {Model}  driver        - The associated `driver` Ember Data record
 * @param {Object} [overrides]   - Optional FullCalendar property overrides (e.g. display, backgroundColor)
 * @returns {Object}  FullCalendar event object
 */
export default function createFullCalendarEventFromScheduleItem(scheduleItem, driver, overrides = {}) {
    const driverName = get(driver, 'name') ?? 'Unknown Driver';
    const title = get(scheduleItem, 'title') || `${driverName} — Shift`;

    return {
        id: `shift-${get(scheduleItem, 'id')}`,
        resourceId: get(scheduleItem, 'assignee_uuid') ?? get(driver, 'id'),
        title,
        start: get(scheduleItem, 'start_at'),
        end: get(scheduleItem, 'end_at'),
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
