import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class VehicleDetailLinkComponent extends Component {
    @service vehicleActions;

    get vehicle() {
        return this.args.vehicle ?? this.args.resource ?? null;
    }

    get title() {
        const vehicle = this.vehicle;
        return vehicle?.displayName || vehicle?.display_name || vehicle?.name || vehicle?.public_id || null;
    }

    /** However this truck is registered — whichever it actually carries. */
    get subtitle() {
        const vehicle = this.vehicle;
        return vehicle?.plate_number || vehicle?.vin || vehicle?.serial_number || vehicle?.call_sign || null;
    }

    /**
     * The console opens a record beside what you were reading rather than
     * navigating away from it; `@mode="route"` asks for the full transition.
     */
    @action open() {
        const vehicle = this.vehicle;
        if (!vehicle) {
            return;
        }

        if (this.args.mode !== 'route' && this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(vehicle);
        }

        return this.vehicleActions.transition.view(vehicle);
    }
}
