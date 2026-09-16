import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, set } from '@ember/object';

/**
 * Picks the vehicle for a driver.
 *
 * The select is handed the chosen vehicle record, not its uuid. ModelSelect
 * only resolves a uuid into a record when it is first rendered; a uuid that
 * arrives later is shown as-is, which is why a picked vehicle used to read
 * "-" in the trigger. The driver still receives both the vehicle and its uuid,
 * which is what the confirm handlers read.
 */
export default class ModalsDriverAssignVehicleComponent extends Component {
    @tracked vehicle = null;

    get selectedVehicle() {
        return this.vehicle ?? this.args.options?.driver?.vehicle_uuid ?? null;
    }

    @action selectVehicle(vehicle) {
        this.vehicle = vehicle;

        const driver = this.args.options?.driver;
        if (driver) {
            set(driver, 'vehicle', vehicle);
            set(driver, 'vehicle_uuid', vehicle?.id ?? null);
        }
    }
}
