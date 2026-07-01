import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class FleetDetailsComponent extends Component {
    @service fleetActions;
    @service driverActions;
    @service vehicleActions;

    get resource() {
        return this.args.resource ?? this.args.fleet;
    }

    get drivers() {
        return this.resourceArray(this.resource?.drivers);
    }

    get driversCount() {
        return Number(this.resource?.drivers_count ?? this.drivers.length ?? 0);
    }

    get driversOnlineCount() {
        return Number(this.resource?.drivers_online_count ?? this.drivers.filter((driver) => driver?.online).length ?? 0);
    }

    resourceArray(resources) {
        if (!resources) {
            return [];
        }

        if (typeof resources.toArray === 'function') {
            return resources.toArray();
        }

        return Array.isArray(resources) ? resources : [];
    }

    @action viewFleet(fleet) {
        return this.fleetActions.panel.view(fleet);
    }

    @action viewDriver(driver) {
        return this.driverActions.panel.view(driver);
    }

    @action viewVehicle(vehicle) {
        return this.vehicleActions.panel.view(vehicle);
    }

    @action assignDriver(fleet) {
        return this.fleetActions.assignDriver(fleet);
    }

    @action assignVehicle(fleet) {
        return this.fleetActions.assignVehicle(fleet);
    }
}
