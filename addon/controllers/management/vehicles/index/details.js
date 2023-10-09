import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class ManagementVehiclesIndexDetailsController extends Controller {
    @tracked view = 'details';
    @tracked queryParams = ['view'];

    @action transitionBack() {
        return this.transitionToRoute('management.vehicles.index');
    }
}
