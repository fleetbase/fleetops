import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { format } from 'date-fns';
import { parseRrule, WEEKDAY_OPTIONS } from '../../utils/recurring-rrule';

export default class CellOrderIdWithSeriesComponent extends Component {
    @service recurringOrderScheduleActions;

    get order() {
        return this.args.row;
    }

    get series() {
        return this.order?.recurring_order_schedule;
    }

    get isSeriesOrder() {
        return Boolean(
            this.order?.is_recurring_generated === true ||
                this.order?.meta?.is_recurring_generated === true ||
                (this.order?.recurring_order_schedule_uuid && this.order?.recurring_occurrence_at) ||
                (this.series?.public_id && this.order?.recurring_occurrence_at)
        );
    }

    get recurrence() {
        return parseRrule(this.series?.rrule);
    }

    get occurrenceDate() {
        const value = this.order?.recurring_occurrence_at ?? this.order?.scheduled_at ?? this.series?.next_occurrence_at;
        if (!value) {
            return null;
        }

        const date = value instanceof Date ? value : new Date(value);

        return Number.isNaN(date.getTime()) ? null : date;
    }

    get occurrenceWeekday() {
        if (!this.occurrenceDate) {
            return null;
        }

        return ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][this.occurrenceDate.getDay()];
    }

    get occurrenceTime() {
        return this.occurrenceDate ? format(this.occurrenceDate, 'HH:mm') : null;
    }

    get occurrenceDateLabel() {
        return this.occurrenceDate ? format(this.occurrenceDate, 'EEE, d MMM yyyy') : null;
    }

    get scheduleDays() {
        const weekdays = this.recurrence.weekdays ?? [];

        if (this.recurrence.frequency === 'daily') {
            return WEEKDAY_OPTIONS.map((day) => ({ ...day, scheduled: true, highlighted: day.code === this.occurrenceWeekday }));
        }

        return WEEKDAY_OPTIONS.map((day) => ({
            ...day,
            scheduled: weekdays.includes(day.code),
            highlighted: day.code === this.occurrenceWeekday,
        }));
    }

    get scheduleSummary() {
        const frequency = this.recurrence.frequency ?? 'weekly';
        const interval = this.recurrence.interval > 1 ? `Every ${this.recurrence.interval} ${frequency}s` : `Every ${frequency}`;

        if (frequency === 'weekly' && this.recurrence.weekdays?.length) {
            const days = this.scheduleDays
                .filter((day) => day.scheduled)
                .map((day) => day.label)
                .join(', ');

            return `${interval} on ${days}`;
        }

        if (frequency === 'monthly' && this.recurrence.monthday) {
            return `${interval} on day ${this.recurrence.monthday}`;
        }

        return interval;
    }

    get spawnedCount() {
        return this.series?.generated_orders_count ?? this.series?.spawned_count ?? this.series?.meta?.spawned_count ?? this.series?.occurrences_count ?? 0;
    }

    @action openOrder(event) {
        event?.preventDefault?.();
        this.args.column?.onLinkClick?.(this.order);
    }

    @action openSeries(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        if (this.series) {
            return this.recurringOrderScheduleActions.transition.view(this.series);
        }
    }

    @action skipNext(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        if (this.series) {
            return this.recurringOrderScheduleActions.skipNextOccurrence(this.series);
        }
    }

    @action pauseSeries(event) {
        event?.preventDefault?.();
        event?.stopPropagation?.();

        if (this.series) {
            return this.recurringOrderScheduleActions.pause(this.series);
        }
    }
}
