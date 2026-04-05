import Component from '@glimmer/component';
import { action } from '@ember/object';

/**
 * Modals::DriverShift
 *
 * Modal for editing an existing ScheduleItem (shift).
 * Pre-populates all fields from the passed `item` option.
 *
 * Plain text/textarea inputs bind @value directly to @options — Ember's
 * two-way binding keeps them in sync without manual update actions.
 * DateTimeInput still uses @onChange because it emits a processed value.
 *
 * @example
 *   this.modalsManager.show('modals/driver-shift', {
 *       item: scheduleItem,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsDriverShiftComponent extends Component {
    constructor() {
        super(...arguments);
        const opts = this.args.options;
        const item = opts?.item;

        // Seed all fields onto @options so the template can bind @value directly
        // and the confirm callback reads them back without any sync actions.
        opts.title = item?.title ?? '';
        opts.startAt = item?.start_at ?? null;
        opts.endAt = item?.end_at ?? null;
        opts.breakStartAt = item?.break_start_at ?? null;
        opts.breakEndAt = item?.break_end_at ?? null;
        opts.notes = item?.notes ?? '';
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
}
