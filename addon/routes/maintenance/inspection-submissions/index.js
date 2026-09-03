import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class MaintenanceInspectionSubmissionsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        public_id: { refreshModel: true },
        status: { refreshModel: true },
        result: { refreshModel: true },
        type: { refreshModel: true },
        vehicle: { refreshModel: true },
        driver: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('inspection-submission', { ...params });
    }
}
