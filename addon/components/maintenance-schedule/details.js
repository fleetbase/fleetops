import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Compute the next N occurrences of a time-based maintenance schedule
 * starting from the given `nextDueDate`, stepping by `intervalValue` `intervalUnit`.
 */
function computeOccurrences(nextDueDate, intervalValue, intervalUnit, count = 12) {
    if (!nextDueDate || !intervalValue || !intervalUnit) return [];
    const occurrences = [];
    let cursor = new Date(nextDueDate);
    for (let i = 0; i < count; i++) {
        occurrences.push(new Date(cursor));
        switch (intervalUnit) {
            case 'days':   cursor.setDate(cursor.getDate() + intervalValue); break;
            case 'weeks':  cursor.setDate(cursor.getDate() + intervalValue * 7); break;
            case 'months': cursor.setMonth(cursor.getMonth() + intervalValue); break;
            case 'years':  cursor.setFullYear(cursor.getFullYear() + intervalValue); break;
            default:       cursor.setDate(cursor.getDate() + intervalValue);
        }
    }
    return occurrences;
}

/**
 * Build a 6-week calendar grid for a given year/month.
 * Each cell: { date, day, isCurrentMonth, isToday, isScheduled }
 */
function buildCalendarGrid(year, month, scheduledDates) {
    const scheduledSet = new Set(scheduledDates.map((d) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`));
    const today = new Date();
    const todayKey = `${today.getFullYear()}-${today.getMonth()}-${today.getDate()}`;
    const firstDay = new Date(year, month, 1);
    const cursor = new Date(year, month, 1 - firstDay.getDay());
    const weeks = [];
    for (let w = 0; w < 6; w++) {
        const week = [];
        for (let d = 0; d < 7; d++) {
            const date = new Date(cursor);
            const key = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
            week.push({ date, day: date.getDate(), isCurrentMonth: date.getMonth() === month, isToday: key === todayKey, isScheduled: scheduledSet.has(key) });
            cursor.setDate(cursor.getDate() + 1);
        }
        weeks.push(week);
    }
    return weeks;
}

const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAY_NAMES = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

export default class MaintenanceScheduleDetailsComponent extends Component {
    @tracked calendarYear;
    @tracked calendarMonth;

    constructor() {
        super(...arguments);
        const seed = this.args.resource?.next_due_date ? new Date(this.args.resource.next_due_date) : new Date();
        this.calendarYear = seed.getFullYear();
        this.calendarMonth = seed.getMonth();
    }

    get resource() { return this.args.resource; }

    get occurrences() {
        const r = this.resource;
        if (!r) return [];
        return computeOccurrences(r.next_due_date, r.interval_value, r.interval_unit, 12);
    }

    get calendarMonthName() { return `${MONTH_NAMES[this.calendarMonth]} ${this.calendarYear}`; }
    get dayNames() { return DAY_NAMES; }

    get calendarWeeks() {
        return buildCalendarGrid(this.calendarYear, this.calendarMonth, this.occurrences);
    }

    get upcomingOccurrences() {
        const now = new Date();
        return this.occurrences.filter((d) => d >= now).slice(0, 6);
    }

    get isTimeBased() {
        const m = this.resource?.interval_method;
        return !m || m === 'time';
    }

    @action prevMonth() {
        if (this.calendarMonth === 0) { this.calendarMonth = 11; this.calendarYear = this.calendarYear - 1; }
        else { this.calendarMonth = this.calendarMonth - 1; }
    }

    @action nextMonth() {
        if (this.calendarMonth === 11) { this.calendarMonth = 0; this.calendarYear = this.calendarYear + 1; }
        else { this.calendarMonth = this.calendarMonth + 1; }
    }
}
