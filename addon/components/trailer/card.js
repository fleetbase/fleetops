import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class TrailerCardComponent extends Component {
    @service trailerActions;
    @service vehicleActions;

    @action viewVehicle() {
        const vehicle = this.args.resource?.current_vehicle;

        if (!vehicle) {
            return;
        }

        if (this.vehicleActions.panel?.view) {
            return this.vehicleActions.panel.view(vehicle);
        }

        return this.vehicleActions.transition.view(vehicle);
    }
}
