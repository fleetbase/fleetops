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
 * Plain text/textarea inputs bind @value directly to @options — Ember's
 * two-way binding keeps them in sync without manual update actions.
 * DateTimeInput still uses @onChange because it emits a processed value.
 *
 * @example (from driver/schedule.js)
 *   this.modalsManager.show('modals/set-driver-availability', {
 *       isAvailable: false,
 *       confirm: async (modal) => { ... }
 *   });
 */
export default class ModalsSetDriverAvailabilityComponent extends Component {
    // isAvailable drives the button selection UI — must stay tracked
    @tracked isAvailable = true;

    constructor() {
        super(...arguments);
        const opts = this.args.options;

        // Seed all fields onto @options so the template can bind @value directly
        // and the confirm callback reads them back without any sync actions.
        opts.startAt = opts.startAt ?? null;
        opts.endAt = opts.endAt ?? null;
        opts.reason = opts.reason ?? '';
        opts.notes = opts.notes ?? '';

        this.isAvailable = opts.isAvailable ?? true;
        opts.isAvailable = this.isAvailable;
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
    toggleAvailability(value) {
        this.isAvailable = value;
        this.args.options.isAvailable = value;
    }
}
