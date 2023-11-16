import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFuelReportsIndexRoute extends Route {
    @service store;

    /**
     * Queryable parameters
     *
     * @var {Object}
     */
    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        vehicle: { refreshModel: true },
        reporter: { refreshModel: true },
        driver: { refreshModel: true },
        status: { refreshModel: true },
        createdAt: { refreshModel: true },
        updatedAt: { refreshModel: true },
        public_id: { refreshModel: true },
        volume: { refreshModel: true },
        odometer: { refreshModel: true },
    };

    model(params) {
        return this.store.query('fuel-report', { ...params, with: ['driver', 'vehicle', 'reporter'] });
    }
}
