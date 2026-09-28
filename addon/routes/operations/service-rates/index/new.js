import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsServiceRatesIndexNewRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops create service-rate')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops.operations.service-rates.index');
        }
    }

    async setupController(controller) {
        super.setupController(...arguments);
        controller.orderConfigs = await this.store.findAll('order-config');
        controller.serviceAreas = await this.store.findAll('service-area');
    }
}
