import Controller from '@ember/controller';
import { action } from '@ember/object';

export default class OperationsOrdersIndexConfigController extends Controller {
    /**
     * Uses router service to transition back to `orders.index`
     *
     * @void
     */
    @action transitionBack() {
        return this.transitionToRoute('operations.orders.index');
    }
}
