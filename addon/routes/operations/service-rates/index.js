import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsServiceRatesIndexRoute extends Route {
    @service store;
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        service_area: { refreshModel: true },
        zone: { refreshModel: true },
    };

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list service-rate')) {
            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console.fleet-ops');
        }
    }

    model(params) {
        return this.store.query('service-rate', {
            ...params,
            with: ['parcelFees', 'rateFees', 'zone', 'serviceArea'],
        });
    }
}
