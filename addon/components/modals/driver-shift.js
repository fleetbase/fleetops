import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Modals::DriverShift
 *
 * Modal for editing an existing ScheduleItem (shift).
 * Pre-populates all fields from the passed `item` option.
 *
 * @example
 *   this.modalsManager.show('modals/driver-shift', {
 *       item: scheduleItem,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsDriverShiftComponent extends Component {
    @tracked title = '';
    @tracked startAt = null;
    @tracked endAt = null;
    @tracked breakStartAt = null;
    @tracked breakEndAt = null;
    @tracked notes = '';

    constructor() {
        super(...arguments);
        const item = this.args.options?.item;
        if (item) {
            this.title = item.title ?? '';
            this.startAt = item.start_at;
            this.endAt = item.end_at;
            this.breakStartAt = item.break_start_at ?? null;
            this.breakEndAt = item.break_end_at ?? null;
            this.notes = item.notes ?? '';
            // Seed modal options so the confirm callback can read them
            this.args.options.title = this.title;
            this.args.options.startAt = this.startAt;
            this.args.options.endAt = this.endAt;
            this.args.options.breakStartAt = this.breakStartAt;
            this.args.options.breakEndAt = this.breakEndAt;
            this.args.options.notes = this.notes;
        }
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
}
