import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get } from '@ember/object';
import config from 'ember-get-config';

export default class DriverCardComponent extends Component {
    @service driverActions;
    @service vehicleActions;

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

    get hasVehicle() {
        return Boolean(get(this.resource, 'vehicle_uuid') ?? get(this.resource, 'vehicle_assigned') ?? get(this.resource, 'vehicle'));
    }

    @action async viewVehicle() {
        const vehicle = (await get(this.resource, 'vehicle')) ?? get(this.resource, 'vehicle_assigned');

        if (!vehicle) {
            return;
        }

        if (this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(vehicle);
        }

        return this.vehicleActions.transition.view(vehicle);
    }
}
