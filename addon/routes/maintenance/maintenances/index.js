import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenanceMaintenancesIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        type: { refreshModel: true },
        status: { refreshModel: true },
        priority: { refreshModel: true },
        public_id: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('maintenance', { ...params });
    }
}
