import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
export default class ManagementDriversIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('driver', public_id);
    }

    async setupController(controller, model) {
        controller.driver = model;
    }
}
