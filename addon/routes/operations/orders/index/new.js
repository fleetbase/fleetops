import Route from '@ember/routing/route';
import { action } from '@ember/object';

export default class OperationsOrdersIndexNewRoute extends Route {
    @action willTransition() {
        if (this.controller) {
            this.controller.resetForm();
        }
    }
}
