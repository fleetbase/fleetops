import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderFormOrchestratorConstraintsComponent extends Component {
    /**
     * Resolves the reference date to use when pre-populating the date portion
     * of a time window field. Prefers scheduled_at, falls back to created_at,
     * and finally falls back to now so there is always a valid date.
     *
     * @returns {Date}
     */
    get _timeWindowReferenceDate() {
        const raw = this.args.resource.scheduled_at ?? this.args.resource.created_at ?? new Date();
        return raw instanceof Date ? raw : new Date(raw);
    }

    /**
     * Called by DateTimeInput @onUpdate for time_window_start and time_window_end.
     *
     * When the user picks a time, we preserve their chosen time but replace the
     * date portion with the order's scheduled_at date (or created_at if
     * scheduled_at is not set). This means the user only ever needs to set the
     * time, the date is always contextually correct.
     *
     * If the incoming value already carries a different date (e.g. the user
     * explicitly changed it via the date part of the picker) we respect that
     * and do not override it.
     *
     * @param {'time_window_start'|'time_window_end'} field
     * @param {Date|string|null} value  Value emitted by DateTimeInput
     */
    @action setTimeWindow(field, value) {
        if (!value) {
            this.args.resource[field] = null;
            return;
        }

        const picked = value instanceof Date ? value : new Date(value);
        if (isNaN(picked.getTime())) {
            this.args.resource[field] = value;
            return;
        }

        const ref = this._timeWindowReferenceDate;

        // Only inject the reference date when the picked value has no meaningful
        // date of its own, i.e. when the date portion is the Unix epoch
        // (1970-01-01), which is what DateTimeInput emits when the user has only
        // touched the time picker and not the date picker.
        const isEpochDate = picked.getUTCFullYear() === 1970 && picked.getUTCMonth() === 0 && picked.getUTCDate() === 1;

        if (isEpochDate) {
            const merged = new Date(ref);
            merged.setHours(picked.getHours(), picked.getMinutes(), picked.getSeconds(), 0);
            this.args.resource[field] = merged;
        } else {
            // User explicitly set a date, honour it as-is.
            this.args.resource[field] = picked;
        }
    }
}
