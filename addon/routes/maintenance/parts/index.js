import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenancePartsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        name: { refreshModel: true },
        public_id: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('part', { ...params });
    }
}
