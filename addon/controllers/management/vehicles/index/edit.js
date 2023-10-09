import Controller from '@ember/controller';
import { action } from '@ember/object';

export default class ManagementVehiclesIndexEditController extends Controller {
    @action transitionBack() {
        return this.transitionToRoute('management.vehicles.index');
    }
}
