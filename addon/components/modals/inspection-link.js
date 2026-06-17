import Component from '@glimmer/component';
import { action, set } from '@ember/object';

export default class ModalsInspectionLinkComponent extends Component {
    get formState() {
        return this.args.options.formState;
    }

    @action assignDriver(driver) {
        set(this.formState, 'driver', driver);
    }

    @action assignVehicle(vehicle) {
        set(this.formState, 'vehicle', vehicle);
    }

    @action updateExpiry(event) {
        set(this.formState, 'expires_at', event.target.value);
    }
}
