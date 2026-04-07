import { get } from '@ember/object';
import { addMinutes } from 'date-fns';
import toCalendarDate from './to-calendar-date';

const STATUS_COLORS = {
    created: '#6366f1',
    dispatched: '#3b82f6',
    active: '#22c55e',
    completed: '#9ca3af',
    cancelled: '#ef4444',
    expired: '#f97316',
};

export function createOrderEventTitle(order) {
    return get(order, 'tracking') ?? get(order, 'public_id');
}

export function createOrderEventDescription(order) {
    const scheduledAtTime = get(order, 'scheduledAtTime');
    const driverAssignedName = get(order, 'driver_assigned.name');
    const vehicleAssignedName = get(order, 'driver_assigned.vehicle_name');
    const destination = get(order, 'pickupName');
    const parts = [];
    if (driverAssignedName) {
        parts.push(driverAssignedName + ' @ ' + scheduledAtTime);
        if (vehicleAssignedName) parts.push(vehicleAssignedName);
        if (destination) parts.push('to ' + destination);
    } else {
        if (scheduledAtTime) parts.push(scheduledAtTime);
        if (destination) parts.push('to ' + destination);
        if (vehicleAssignedName) parts.push(vehicleAssignedName);
    }
    return parts.filter(Boolean).join('\n');
}

/**
 * Converts an order Ember Data record into a calendar event object for
 * @event-calendar/core.
 *
 * @event-calendar/core has no timezone support — it reads the browser-local
 * fields of any Date passed as `start`/`end` and positions the event at that
 * wall-clock time.  To display events in the company timezone regardless of
 * the browser's timezone, we pass a "fake local" Date whose local fields equal
 * the company wall-clock time.  See `to-calendar-date.js` for details.
 *
 * @param {Model}  order     Ember Data `order` record.
 * @param {string} timezone  IANA timezone string for the company, e.g. 'Asia/Singapore'.
 * @returns {Object}  Calendar event object.
 */
export default function createFullCalendarEventFromOrder(order, timezone) {
    const scheduledAt = get(order, 'scheduled_at');
    const duration = get(order, 'estimated_duration') ?? 60;

    // Convert the UTC scheduled_at to a wall-clock Date in the company timezone.
    const startUtc = scheduledAt ? new Date(scheduledAt) : null;
    const start = startUtc ? toCalendarDate(startUtc, timezone) : null;
    const end = start ? toCalendarDate(addMinutes(startUtc, duration), timezone) : null;

    const status = get(order, 'status') ?? 'created';
    const color = STATUS_COLORS[status] ?? STATUS_COLORS.created;
    return {
        id: get(order, 'id'),
        resourceId: get(order, 'driver_assigned_uuid') ?? null,
        title: createOrderEventTitle(order),
        description: createOrderEventDescription(order),
        start,
        end,
        display: 'block',
        backgroundColor: color,
        borderColor: color,
        textColor: '#ffffff',
        extendedProps: { order, status, type: 'order' },
    };
}
