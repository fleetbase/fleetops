import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';
import config from 'ember-get-config';

export default class DriverCardComponent extends Component {
    @service driverActions;

    get resource() {
        return this.args.resource;
    }

    get fallbackImage() {
        return config?.defaultValues?.driverImage;
    }

    get statusValue() {
        return get(this.resource, 'status') ?? '';
    }

    get statusLabel() {
        return this.statusValue || '-';
    }

    get assignedVehicleLabel() {
        const driver = this.resource;

        return get(driver, 'vehicle_assigned.display_name') ?? get(driver, 'vehicle.display_name') ?? get(driver, 'vehicle_name');
    }
}
