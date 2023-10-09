import Controller from '@ember/controller';
import { action } from '@ember/object';

export default class ManagementFleetsIndexDetailsController extends Controller {
    @action transitionBack() {
        return this.transitionToRoute('management.fleets.index');
    }
}
