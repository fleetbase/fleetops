import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import config from 'ember-get-config';

/**
 * A vehicle as a select option: photo, then name over one identifier. The
 * plate when it has one; otherwise its VIN, serial number or call sign,
 * whichever it has first, labelled so a bare string of characters is not
 * mistaken for a plate.
 */
export default class SelectOptionVehicleComponent extends Component {
    @service intl;

    get vehicle() {
        return this.args.option ?? this.args.model ?? null;
    }

    get photo() {
        return this.vehicle?.photo_url || this.vehicle?.avatar_url || null;
    }

    get fallbackPhoto() {
        return get(config, 'defaultValues.vehicleImage');
    }

    get title() {
        const vehicle = this.vehicle;
        return vehicle?.displayName || vehicle?.display_name || vehicle?.name || vehicle?.public_id;
    }

    get identifier() {
        const vehicle = this.vehicle;

        if (!vehicle) {
            return null;
        }

        if (vehicle.plate_number) {
            return vehicle.plate_number;
        }

        for (const key of ['vin', 'serial_number', 'call_sign']) {
            if (vehicle[key]) {
                return `${this.intl.t(`select-option.vehicle.${key}`)} ${vehicle[key]}`;
            }
        }

        return null;
    }

    get details() {
        return [this.identifier];
    }
}
