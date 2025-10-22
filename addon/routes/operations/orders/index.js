import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class OperationsOrdersIndexRoute extends Route {
    @service store;
    @service leafletMapManager;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        public_id: { refreshModel: true },
        internal_id: { refreshModel: true },
        payload: { refreshModel: true },
        tracking: { refreshModel: true },
        facilitator: { refreshModel: true },
        driver: { refreshModel: true },
        vehicle: { refreshModel: true },
        customer: { refreshModel: true },
        pickup: { refreshModel: true },
        dropoff: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
        scheduled_at: { refreshModel: true },
        without_driver: { refreshModel: true },
        bulk_query: { refreshModel: true },
        after: { refreshModel: true },
        before: { refreshModel: true },
        type: { refreshModel: true },
        layout: { refreshModel: false },
    };

    model(params) {
        return this.store.query('order', params);
    }
}
