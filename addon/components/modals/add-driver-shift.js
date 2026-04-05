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
 * Options accepted:
 *   - drivers: array of Driver records (for the dropdown in the global scheduler)
 *   - selectedDriver: pre-selected Driver record (when opened from driver panel)
 *   - schedule: the driver's Schedule record (required for recurring mode)
 */
export default class ModalsAddDriverShiftComponent extends Component {
    @service intl;

    // ── Mode ──────────────────────────────────────────────────────────────────
    @tracked isRecurring = false;

    // ── Shared ────────────────────────────────────────────────────────────────
    @tracked selectedDriver = null;

    // ── Single-shift fields ───────────────────────────────────────────────────
    @tracked title = '';
    @tracked startAt = null;
    @tracked endAt = null;
    @tracked breakStartAt = null;
    @tracked breakEndAt = null;
    @tracked notes = '';

    // ── Recurring-schedule fields ─────────────────────────────────────────────
    @tracked selectedDays = [];
    @tracked shiftStartTime = '08:00';
    @tracked shiftEndTime = '16:00';
    @tracked breakStartTime = '';
    @tracked breakEndTime = '';
    @tracked templateName = '';
    @tracked templateColor = '#6366f1';
    @tracked recurrenceStartDate = null;
    @tracked recurrenceEndDate = null;

    constructor() {
        super(...arguments);
        this.selectedDriver = this.args.options?.selectedDriver ?? null;
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
        const times = this.shiftStartTime && this.shiftEndTime ? ` · ${this.shiftStartTime}–${this.shiftEndTime}` : '';
        return `Weekly on ${days}${times}`;
    }

    get canConfirm() {
        if (this.isRecurring) {
            return this.selectedDays.length > 0 && this.shiftStartTime && this.shiftEndTime;
        }
        return this.startAt && this.endAt;
    }

    // ── Actions ───────────────────────────────────────────────────────────────
    @action
    toggleMode() {
        this.isRecurring = !this.isRecurring;
    }

    @action
    toggleDay(code) {
        if (this.selectedDays.includes(code)) {
            this.selectedDays = this.selectedDays.filter((d) => d !== code);
        } else {
            this.selectedDays = [...this.selectedDays, code];
        }
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

    @action
    updateTitle(value) {
        this.title = value;
        this.args.options.title = value;
    }

    @action
    updateStartAt(value) {
        this.startAt = value;
        this.args.options.startAt = value;
    }

    @action
    updateEndAt(value) {
        this.endAt = value;
        this.args.options.endAt = value;
    }

    @action
    updateBreakStartAt(value) {
        this.breakStartAt = value;
        this.args.options.breakStartAt = value;
    }

    @action
    updateBreakEndAt(value) {
        this.breakEndAt = value;
        this.args.options.breakEndAt = value;
    }

    @action
    updateNotes(value) {
        this.notes = value;
        this.args.options.notes = value;
    }

    @action
    updateTemplateName(value) {
        this.templateName = value;
        this.args.options.templateName = value;
    }

    @action
    updateTemplateColor(value) {
        this.templateColor = value;
        this.args.options.templateColor = value;
    }

    @action
    updateShiftStartTime(value) {
        this.shiftStartTime = value;
        this.args.options.shiftStartTime = value;
    }

    @action
    updateShiftEndTime(value) {
        this.shiftEndTime = value;
        this.args.options.shiftEndTime = value;
    }

    @action
    updateBreakStartTime(value) {
        this.breakStartTime = value;
        this.args.options.breakStartTime = value;
    }

    @action
    updateBreakEndTime(value) {
        this.breakEndTime = value;
        this.args.options.breakEndTime = value;
    }

    @action
    updateRecurrenceStartDate(value) {
        this.recurrenceStartDate = value;
        this.args.options.recurrenceStartDate = value;
    }

    @action
    updateRecurrenceEndDate(value) {
        this.recurrenceEndDate = value;
        this.args.options.recurrenceEndDate = value;
    }

    /**
     * Build the RRULE string from the selected days and optional end date.
     * Example output: FREQ=WEEKLY;BYDAY=MO,TU,TH
     */
    buildRrule() {
        const parts = [`FREQ=WEEKLY`, `BYDAY=${this.selectedDays.join(',')}`];
        if (this.recurrenceEndDate) {
            const d = new Date(this.recurrenceEndDate);
            const until = d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
            parts.push(`UNTIL=${until}`);
        }
        return parts.join(';');
    }

    /**
     * Expose the current mode and RRULE to the options object so the
     * confirm callback in driver/schedule.js can read them.
     */
    @action
    syncOptionsMode() {
        this.args.options.isRecurring = this.isRecurring;
        if (this.isRecurring) {
            this.args.options.rrule = this.buildRrule();
        }
    }
}
