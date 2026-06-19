import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivitySensorsIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        telematic: { refreshModel: true },
        device: { refreshModel: true },
        type: { refreshModel: true },
        status: { refreshModel: true },
        serial_number: { refreshModel: true },
        imei: { refreshModel: true },
        last_reading_at: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('sensor', { ...params });
    }
}
