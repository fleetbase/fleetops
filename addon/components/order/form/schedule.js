import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import { buildRrule, parseRrule, WEEKDAY_OPTIONS } from '../../../utils/recurring-rrule';

const FREQUENCY_OPTIONS = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'yearly', label: 'Yearly' },
];

export default class OrderFormScheduleComponent extends Component {
    @service recurringOrderScheduleActions;

    @tracked frequency = 'weekly';
    @tracked interval = 1;
    @tracked selectedWeekdays = ['MO'];
    @tracked monthday = new Date().getDate();
    @tracked previewOccurrences = [];

    weekdayOptions = WEEKDAY_OPTIONS;
    frequencyOptions = FREQUENCY_OPTIONS;

    constructor() {
        super(...arguments);
        this.syncFromDraft();

        if (this.args.repeatEnabled) {
            this.updatePreview.perform();
        }
    }

    get seriesDraft() {
        return this.args.seriesDraft;
    }

    get isWeekly() {
        return this.frequency === 'weekly';
    }

    get isMonthly() {
        return this.frequency === 'monthly';
    }

    get currentRrule() {
        return buildRrule({
            frequency: this.frequency,
            interval: this.interval,
            weekdays: this.selectedWeekdays,
            monthday: this.monthday,
            until: this.seriesDraft?.ends_at,
        });
    }

    syncFromDraft() {
        const parsedRule = parseRrule(this.seriesDraft?.rrule);
        this.frequency = parsedRule.frequency;
        this.interval = parsedRule.interval;
        this.selectedWeekdays = parsedRule.weekdays.length > 0 ? parsedRule.weekdays : ['MO'];
        this.monthday = parsedRule.monthday ?? new Date(this.args.resource?.scheduled_at ?? Date.now()).getDate();
    }

    commitRule() {
        if (this.seriesDraft) {
            this.seriesDraft.rrule = this.currentRrule;
        }
    }

    @task({ restartable: true }) *updatePreview() {
        if (!this.seriesDraft || !this.args.repeatEnabled) {
            this.previewOccurrences = [];
            return;
        }

        try {
            this.seriesDraft.starts_at = this.args.resource?.scheduled_at ?? this.seriesDraft.starts_at ?? new Date();
            this.seriesDraft.draftOrder = this.args.resource;
            const response = yield this.recurringOrderScheduleActions.preview(this.seriesDraft, 5, { rrule: this.currentRrule });
            this.previewOccurrences = response?.occurrences ?? [];
        } catch {
            this.previewOccurrences = [];
        }
    }

    @action updateScheduledAt(value) {
        this.args.resource.scheduled_at = value;

        if (this.seriesDraft) {
            this.seriesDraft.starts_at = value;
        }

        this.updatePreview.perform();
    }

    @action toggleRepeat(enabled) {
        this.args.onRepeatChange?.(enabled);

        if (enabled) {
            this.commitRule();
            this.updatePreview.perform();
        } else {
            this.previewOccurrences = [];
        }
    }

    @action updateFrequency(option) {
        this.frequency = option.value;
        this.commitRule();
        this.updatePreview.perform();
    }

    @action updateInterval({ target }) {
        this.interval = Number(target.value) || 1;
        this.commitRule();
        this.updatePreview.perform();
    }

    @action updateMonthday({ target }) {
        this.monthday = Number(target.value) || 1;
        this.commitRule();
        this.updatePreview.perform();
    }

    @action updateEndsAt(value) {
        if (this.seriesDraft) {
            this.seriesDraft.ends_at = value;
            this.commitRule();
        }

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

        this.commitRule();
        this.updatePreview.perform();
    }

    @action isWeekdaySelected(code) {
        return this.selectedWeekdays.includes(code);
    }
}
