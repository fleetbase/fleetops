import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Modals::AddDriverShift
 *
 * Supports two modes toggled by the user:
 *   1. "Single shift" — creates one ScheduleItem on a specific date/time
 *   2. "Recurring schedule" — creates a ScheduleTemplate (RRULE) and triggers
 *      materialisation for the rolling 60-day horizon
 *
 * Plain text/textarea inputs bind @value directly to @options properties —
 * Ember's two-way binding keeps them in sync without manual update actions.
 * Custom components (DateTimeInput, InputGroup @type="time", ModelSelect)
 * still use @onChange because they emit processed values, not DOM events.
 *
 * Options accepted:
 *   - drivers: array of Driver records (for the dropdown in the global scheduler)
 *   - selectedDriver: pre-selected Driver record (when opened from driver panel)
 *   - schedule: the driver's Schedule record (required for recurring mode)
 */
export default class ModalsAddDriverShiftComponent extends Component {
    @service intl;

    // ── Mode ──────────────────────────────────────────────────────────────────
    @tracked isRecurring = true;

    // ── Shared ────────────────────────────────────────────────────────────────
    @tracked selectedDriver = null;

    // ── Recurring-schedule fields that drive computed getters ─────────────────
    @tracked selectedDays = [];

    constructor() {
        super(...arguments);
        const opts = this.args.options;

        // Seed all plain-text / date / time fields onto @options so the template
        // can bind @value directly and the confirm callback reads them back.
        opts.title = opts.title ?? '';
        opts.notes = opts.notes ?? '';
        opts.startAt = opts.startAt ?? null;
        opts.endAt = opts.endAt ?? null;
        opts.breakStartAt = opts.breakStartAt ?? null;
        opts.breakEndAt = opts.breakEndAt ?? null;
        opts.templateName = opts.templateName ?? '';
        opts.templateColor = opts.templateColor ?? '#6366f1';
        opts.shiftStartTime = opts.shiftStartTime ?? '08:00';
        opts.shiftEndTime = opts.shiftEndTime ?? '16:00';
        opts.breakStartTime = opts.breakStartTime ?? '';
        opts.breakEndTime = opts.breakEndTime ?? '';
        opts.recurrenceStartDate = opts.recurrenceStartDate ?? null;
        opts.recurrenceEndDate = opts.recurrenceEndDate ?? null;
        opts.isRecurring = true;

        this.selectedDriver = opts.selectedDriver ?? null;
    }

    // ── Getters ───────────────────────────────────────────────────────────────
    get drivers() {
        return this.args.options?.drivers ?? [];
    }

    get hasManyDrivers() {
        return this.drivers.length > 1;
    }

    get dayOptions() {
        return [
            { code: 'MO', label: this.intl.t('scheduler.day-mon') },
            { code: 'TU', label: this.intl.t('scheduler.day-tue') },
            { code: 'WE', label: this.intl.t('scheduler.day-wed') },
            { code: 'TH', label: this.intl.t('scheduler.day-thu') },
            { code: 'FR', label: this.intl.t('scheduler.day-fri') },
            { code: 'SA', label: this.intl.t('scheduler.day-sat') },
            { code: 'SU', label: this.intl.t('scheduler.day-sun') },
        ];
    }

    get recurrenceSummary() {
        if (!this.selectedDays.length) return null;
        const dayMap = { MO: 'Mon', TU: 'Tue', WE: 'Wed', TH: 'Thu', FR: 'Fri', SA: 'Sat', SU: 'Sun' };
        const days = this.selectedDays.map((d) => dayMap[d]).join(', ');
        const opts = this.args.options;
        const times = opts.shiftStartTime && opts.shiftEndTime ? ` · ${opts.shiftStartTime}–${opts.shiftEndTime}` : '';
        return `Weekly on ${days}${times}`;
    }

    /**
     * The toggle shows "One-off shift" and is ON when NOT recurring.
     * isRecurring is the source of truth; isOneOff is the inverse for the toggle.
     */
    get isOneOff() {
        return !this.isRecurring;
    }

    get canConfirm() {
        const opts = this.args.options;
        if (this.isRecurring) {
            return this.selectedDays.length > 0 && opts.shiftStartTime && opts.shiftEndTime;
        }
        return opts.startAt && opts.endAt;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    @action
    toggleMode() {
        this.isRecurring = !this.isRecurring;
        this.args.options.isRecurring = this.isRecurring;
        if (this.isRecurring) {
            this.args.options.rrule = this.buildRrule();
        }
    }

    @action
    toggleDay(code) {
        if (this.selectedDays.includes(code)) {
            this.selectedDays = this.selectedDays.filter((d) => d !== code);
        } else {
            this.selectedDays = [...this.selectedDays, code];
        }
        // Keep rrule on options in sync
        this.args.options.rrule = this.buildRrule();
    }

    @action
    isDaySelected(code) {
        return this.selectedDays.includes(code);
    }

    @action
    selectDriver(driver) {
        this.selectedDriver = driver;
        this.args.options.selectedDriver = driver;
    }

    // DateTimeInput emits a processed value — needs @onChange
    @action
    updateStartAt(value) {
        this.args.options.startAt = value;
    }

    @action
    updateEndAt(value) {
        this.args.options.endAt = value;
    }

    @action
    updateBreakStartAt(value) {
        this.args.options.breakStartAt = value;
    }

    @action
    updateBreakEndAt(value) {
        this.args.options.breakEndAt = value;
    }

    // InputGroup @type="time" emits a processed value — needs @onChange
    @action
    updateShiftStartTime(value) {
        this.args.options.shiftStartTime = value;
        // Recompute rrule summary reactivity trigger
        this.args.options.rrule = this.buildRrule();
    }

    @action
    updateShiftEndTime(value) {
        this.args.options.shiftEndTime = value;
        this.args.options.rrule = this.buildRrule();
    }

    @action
    updateBreakStartTime(value) {
        this.args.options.breakStartTime = value;
    }

    @action
    updateBreakEndTime(value) {
        this.args.options.breakEndTime = value;
    }

    @action
    updateRecurrenceStartDate(value) {
        this.args.options.recurrenceStartDate = value;
    }

    @action
    updateRecurrenceEndDate(value) {
        this.args.options.recurrenceEndDate = value;
        this.args.options.rrule = this.buildRrule();
    }

    /**
     * Build the RRULE string from the selected days and optional end date.
     * Example output: FREQ=WEEKLY;BYDAY=MO,TU,TH
     */
    buildRrule() {
        const parts = [`FREQ=WEEKLY`, `BYDAY=${this.selectedDays.join(',')}`];
        const endDate = this.args.options.recurrenceEndDate;
        if (endDate) {
            const d = new Date(endDate);
            const until = d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
            parts.push(`UNTIL=${until}`);
        }
        return parts.join(';');
    }
}
