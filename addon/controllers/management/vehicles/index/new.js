import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class ManagementVehiclesIndexNewController extends Controller {
    @service store;

    /**
     * The vehicle being created.
     *
     * @var {VehicleModel}
     */
    @tracked vehicle = this.store.createRecord('vehicle', {
        status: `active`,
    });

    @action transitionBack() {
        return this.transitionToRoute('management.vehicles.index');
    }
}
