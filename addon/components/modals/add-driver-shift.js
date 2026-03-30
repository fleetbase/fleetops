import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Modals::AddDriverShift
 *
 * Modal for creating a new shift (ScheduleItem) for a driver.
 * Used from both the global scheduler (operations/scheduler) and the
 * per-driver schedule panel (driver/schedule).
 *
 * Options accepted:
 *   - drivers: array of Driver records (for the dropdown in the global scheduler)
 *   - selectedDriver: pre-selected Driver record (when opened from driver panel)
 *
 * @example
 *   this.modalsManager.show('modals/add-driver-shift', {
 *       selectedDriver: this.args.resource,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsAddDriverShiftComponent extends Component {
    @tracked startAt = null;
    @tracked endAt = null;
    @tracked title = '';
    @tracked notes = '';
    @tracked selectedDriver = null;

    constructor() {
        super(...arguments);
        this.selectedDriver = this.args.options?.selectedDriver ?? null;
    }

    get drivers() {
        return this.args.options?.drivers ?? [];
    }

    get hasManyDrivers() {
        return this.drivers.length > 1;
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

    @action
    selectDriver(driver) {
        this.selectedDriver = driver;
        this.args.options.selectedDriver = driver;
    }
}
