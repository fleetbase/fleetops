import Component from '@glimmer/component';
import { action } from '@ember/object';
import { primaryActionFor, secondaryActionsFor, snoozePayloadFor } from '../../utils/radar';

export default class RadarItemDrawerComponent extends Component {
    overlay = null;

    get primaryAction() {
        return primaryActionFor(this.args.item);
    }

    get recordActions() {
        const primary = this.primaryAction;

        return primary ? [primary, ...secondaryActionsFor(this.args.item)] : secondaryActionsFor(this.args.item);
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

    @action setOverlay(overlay) {
        this.overlay = overlay;
    }

    @action snooze(preset) {
        return this.args.onAct?.(this.args.item, 'snooze', { preset, ...snoozePayloadFor(preset) });
    }
}
