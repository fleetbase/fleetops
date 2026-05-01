import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { buildRrule, parseRrule, WEEKDAY_OPTIONS } from '../../utils/recurring-rrule';
import { createRecurringDraftOrder } from '../../utils/recurring-order-blueprint';

const FREQUENCY_OPTIONS = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
];

const STATUS_OPTIONS = ['active', 'paused', 'canceled'];

export default class RecurringOrderScheduleFormComponent extends Component {
    @service store;
    @service recurringOrderScheduleActions;
    @service serviceRateActions;

    @tracked draftOrder;
    @tracked frequency = 'weekly';
    @tracked interval = 1;
    @tracked selectedWeekdays = ['MO'];
    @tracked monthday = null;
    @tracked previewOccurrences = [];
    @tracked serviceRates = [];
    @tracked selectedServiceRate = null;

    weekdayOptions = WEEKDAY_OPTIONS;
    frequencyOptions = FREQUENCY_OPTIONS;
    statusOptions = STATUS_OPTIONS;

    constructor() {
        super(...arguments);

        const { resource, sourceOrder } = this.args;
        const parsedRule = parseRrule(resource.rrule);

        this.frequency = parsedRule.frequency;
        this.interval = parsedRule.interval;
        this.selectedWeekdays = parsedRule.weekdays.length > 0 ? parsedRule.weekdays : ['MO'];
        this.monthday = parsedRule.monthday ?? resource.starts_at?.getDate?.() ?? new Date().getDate();

        this.draftOrder = resource.draftOrder ?? createRecurringDraftOrder(this.store, sourceOrder ?? resource);
        resource.draftOrder = this.draftOrder;

        if (!resource.name && sourceOrder) {
            resource.name = `Recurring ${sourceOrder.tracking ?? sourceOrder.public_id ?? 'Order'}`;
        }

        if (resource.starts_at && !this.draftOrder.scheduled_at) {
            this.draftOrder.scheduled_at = resource.starts_at;
        }

        this.selectedServiceRate = resource.service_rate ?? null;
        resource.service_rate_uuid = resource.service_rate_uuid ?? resource.service_rate?.id ?? null;
        this.syncRrule();
        this.updatePreview.perform();
    }

    get isWeekly() {
        return this.frequency === 'weekly';
    }

    get isMonthly() {
        return this.frequency === 'monthly';
    }

    get canQueryServiceRates() {
        return this.draftOrder?.payloadCoordinates?.length >= 2;
    }

    syncRrule() {
        this.args.resource.rrule = buildRrule({
            frequency: this.frequency,
            interval: this.interval,
            weekdays: this.selectedWeekdays,
            monthday: this.monthday,
            until: this.args.resource.ends_at,
        });
    }

    @task *updatePreview() {
        this.syncRrule();

        if (!this.args.resource.starts_at) {
            this.previewOccurrences = [];
            return;
        }

        try {
            const response = yield this.recurringOrderScheduleActions.preview(this.args.resource, 8);
            this.previewOccurrences = response?.occurrences ?? [];
        } catch {
            this.previewOccurrences = [];
        }
    }

    @task *loadServiceRates() {
        if (!this.canQueryServiceRates) {
            this.serviceRates = [];
            return;
        }

        this.serviceRates = yield this.serviceRateActions.queryServiceRatesForOrder.perform(this.draftOrder);
    }

    @action updateStartsAt(value) {
        this.args.resource.starts_at = value;
        this.draftOrder.scheduled_at = value;
        this.updatePreview.perform();
    }

    @action updateEndsAt(value) {
        this.args.resource.ends_at = value;
        this.updatePreview.perform();
    }

    @action updateFrequency(option) {
        this.frequency = option.value;
        this.updatePreview.perform();
    }

    @action updateInterval({ target }) {
        this.interval = Number(target.value) || 1;
        this.updatePreview.perform();
    }

    @action updateMonthday({ target }) {
        this.monthday = Number(target.value) || 1;
        this.updatePreview.perform();
    }

    @action toggleWeekday(code) {
        if (this.selectedWeekdays.includes(code)) {
            this.selectedWeekdays = this.selectedWeekdays.filter((value) => value !== code);
        } else {
            this.selectedWeekdays = [...this.selectedWeekdays, code];
        }

        if (this.selectedWeekdays.length === 0) {
            this.selectedWeekdays = ['MO'];
        }

        this.updatePreview.perform();
    }

    @action isWeekdaySelected(code) {
        return this.selectedWeekdays.includes(code);
    }

    @action selectServiceRate(serviceRate) {
        this.selectedServiceRate = serviceRate;
        this.args.resource.service_rate = serviceRate;
        this.args.resource.service_rate_uuid = serviceRate?.id ?? null;
    }
}
