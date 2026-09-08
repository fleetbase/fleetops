import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class VehicleCardComponent extends Component {
    @service vehicleActions;
    @service driverActions;

    get driverName() {
        const vehicle = this.args.resource;

        return vehicle?.driver_name ?? vehicle?.driver?.name ?? null;
    }

    @action viewDriver() {
        const driver = this.args.resource?.driver;

        if (!driver) {
            return;
        }

        if (this.driverActions.panel?.view) {
            return this.driverActions.panel.view(driver);
        }

        return this.driverActions.transition.view(driver);
    }
}
