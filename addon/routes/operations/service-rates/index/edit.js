import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class OperationsServiceRatesIndexEditRoute extends Route {
    @service store;
    @service currentUser;
    @service notifications;

    templateName = 'operations.service-rates.index.new';

    @action error(error) {
        this.notifications.serverError(error);
        return this.transitionTo('operations.service-rates.index');
    }

    model({ public_id }) {
        return this.store.queryRecord('service-rate', {
            public_id,
            single: true,
            with: ['parcelFees', 'rateFees'],
        });
    }

    async setupController(controller, model) {
        controller.serviceRate = model;

        if (model.isFixedMeter) {
            controller.rateFees = model.rate_fees;
        }

        if (model.isParcelService) {
            controller.parcelFees = model.parcel_fees;
        }

        const serviceTypes = await this.currentUser.getInstalledOrderConfigs();
        const serviceAreas = await this.store.findAll('service-area');

        controller.serviceTypes = serviceTypes;
        controller.serviceAreas = serviceAreas;
    }
}
