import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Modals::DriverShift
 *
 * Modal for editing an existing ScheduleItem (shift).
 * Pre-populates fields from the passed `item` option.
 *
 * @example
 *   this.modalsManager.show('modals/driver-shift', {
 *       item: scheduleItem,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsDriverShiftComponent extends Component {
    @tracked startAt = null;
    @tracked endAt = null;
    @tracked title = '';
    @tracked notes = '';

    constructor() {
        super(...arguments);
        const item = this.args.options?.item;
        if (item) {
            this.startAt = item.start_at;
            this.endAt = item.end_at;
            this.title = item.title ?? '';
            this.notes = item.notes ?? '';
            // Seed modal options so the confirm callback can read them
            this.args.options.startAt = item.start_at;
            this.args.options.endAt = item.end_at;
        }
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
    updateTitle(value) {
        this.title = value;
        this.args.options.title = value;
    }

    @action
    updateNotes(value) {
        this.notes = value;
        this.args.options.notes = value;
    }
}
