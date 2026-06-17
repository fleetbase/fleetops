import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenanceInspectionFormsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        public_id: { refreshModel: true },
        status: { refreshModel: true },
        type: { refreshModel: true },
        frequency: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('inspection-form', { ...params });
    }
}
