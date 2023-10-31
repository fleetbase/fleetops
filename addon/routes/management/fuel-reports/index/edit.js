import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFuelReportsIndexEditRoute extends Route {
    @service store;

    model({ public_id }) {
        return this.store.findRecord('fuel-report', public_id);
    }

    async setupController(controller, model) {
        controller.fuelReport = model;
    }
}
