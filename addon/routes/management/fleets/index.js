import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ManagementFleetsIndexRoute extends Route {
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
        status: { refreshModel: true },
        internal_id: { refreshModel: true },
        zone: { refreshModel: true },
        service_area: { refreshModel: true },
        parent_fleet: { refreshModel: true },
        task: { refreshModel: true },
    };

    model(params) {
        return this.store.query('fleet', { ...params });
    }
}
