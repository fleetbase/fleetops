import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

/**
 * Modals::SetDriverAvailability
 *
 * Shared modal for both "Set Availability" (is_available=true) and
 * "Request Time Off" (is_available=false) flows. The caller controls
 * the initial value of `isAvailable` via modal options.
 *
 * @example (from driver/schedule.js)
 *   this.modalsManager.show('modals/set-driver-availability', {
 *       isAvailable: false,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsSetDriverAvailabilityComponent extends Component {
    @tracked startAt = null;
    @tracked endAt = null;
    @tracked reason = '';
    @tracked notes = '';
    @tracked isAvailable = true;

    constructor() {
        super(...arguments);
        // Initialise from modal options so the caller can pre-set isAvailable
        this.isAvailable = this.args.options?.isAvailable ?? true;
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
    updateReason(value) {
        this.reason = value;
        this.args.options.reason = value;
    }

    @action
    updateNotes(value) {
        this.notes = value;
        this.args.options.notes = value;
    }

    @action
    toggleAvailability(value) {
        this.isAvailable = value;
        this.args.options.isAvailable = value;
    }
}
