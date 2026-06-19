import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityEventsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        telematic: { refreshModel: true },
        device: { refreshModel: true },
        event_type: { refreshModel: true },
        severity: { refreshModel: true },
        processed: { refreshModel: true },
        occurred_at: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('device-event', { ...params });
    }
}
