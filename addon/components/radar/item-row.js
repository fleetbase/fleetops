import Component from '@glimmer/component';
import { action } from '@ember/object';
import { chipStatusFor, primaryActionFor, snoozePayloadFor } from '../../utils/radar';

export default class RadarItemRowComponent extends Component {
    get chipStatus() {
        return chipStatusFor(this.args.item);
    }

    get primaryAction() {
        return primaryActionFor(this.args.item);
    }

    get isAcknowledged() {
        return this.args.item?.state?.status === 'acknowledged';
    }

    get isSnoozed() {
        return this.args.item?.state?.status === 'snoozed';
    }

    get isResolved() {
        return this.args.item?.state?.status === 'resolved';
    }

    /** Whether Shift was held on the click that is toggling the checkbox. */
    shiftHeld = false;

    @action noteShift(event) {
        this.shiftHeld = Boolean(event?.shiftKey);
    }

    @action toggleSelect(checked) {
        const range = this.shiftHeld;
        this.shiftHeld = false;

        return this.args.onSelect?.(this.args.item, { range, checked });
    }

    @action snooze(preset) {
        return this.args.onAct?.(this.args.item, 'snooze', { preset, ...snoozePayloadFor(preset) });
    }
}
