import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format as formatDate, isValid as isValidDate } from 'date-fns';

/**
 * ScheduleItem model
 *
 * Represents a single shift window assigned to a driver (or any other
 * polymorphic assignee) via the core-api scheduling system.
 *
 * The `assignee_uuid` / `assignee_type` pair is the polymorphic FK that
 * links this record back to a Driver (or other resource).  The Driver
 * model exposes a `currentShift` hasOne relationship on the PHP side
 * which the order scheduler eager-loads with `with: ['currentShift']`.
 */
export default class ScheduleItemModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') schedule_uuid;

    /** @polymorphic assignee */
    @attr('string') assignee_uuid;
    @attr('string') assignee_type;

    /** @polymorphic resource (optional — the vehicle, route, etc.) */
    @attr('string') resource_uuid;
    @attr('string') resource_type;

    /** @attributes */
    @attr('string') title;
    @attr('string') notes;
    @attr('string') status;
    @attr('number') duration;

    /** @dates */
    @attr('date') start_at;
    @attr('date') end_at;
    @attr('date') break_start_at;
    @attr('date') break_end_at;
    @attr('date') deleted_at;
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @meta */
    @attr('raw') meta;

    // -------------------------------------------------------------------------
    // Computed helpers
    // -------------------------------------------------------------------------

    @computed('start_at')
    get startAtFormatted() {
        if (!isValidDate(this.start_at)) {
            return null;
        }
        return formatDate(this.start_at, 'HH:mm');
    }

    @computed('end_at')
    get endAtFormatted() {
        if (!isValidDate(this.end_at)) {
            return null;
        }
        return formatDate(this.end_at, 'HH:mm');
    }

    @computed('start_at', 'end_at')
    get shiftLabel() {
        const start = this.startAtFormatted;
        const end = this.endAtFormatted;
        if (start && end) {
            return `${start} – ${end}`;
        }
        return this.title || 'Shift';
    }

    @computed('status')
    get isActive() {
        return this.status === 'active';
    }

    @computed('status')
    get isScheduled() {
        return this.status === 'scheduled';
    }
}
