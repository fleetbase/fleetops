import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsServiceRatesIndexNewRoute extends Route {
    @service store;
    @service currentUser;

    async setupController(controller) {
        const serviceTypes = await this.currentUser.getInstalledOrderConfigs();
        const serviceAreas = await this.store.findAll('service-area');

        controller.serviceTypes = serviceTypes;
        controller.serviceAreas = serviceAreas;
    }
}
