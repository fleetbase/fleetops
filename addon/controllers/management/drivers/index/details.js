import Controller from '@ember/controller';
import { action } from '@ember/object';

export default class ManagementDriverIndexDetailsController extends Controller {
    @action transitionBack() {
        return this.transitionToRoute('management.drivers.index');
    }
}
