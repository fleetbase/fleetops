import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        name: { refreshModel: true },
        public_id: { refreshModel: true },
        status: { refreshModel: true },
        attachment_state: { refreshModel: true },
        telematic: { refreshModel: true },
        provider: { refreshModel: true },
        vehicle: { refreshModel: true },
        connection_status: { refreshModel: true },
        device_id: { refreshModel: true },
        type: { refreshModel: true },
        serial_number: { refreshModel: true },
        last_online_at: { refreshModel: true },
        created_at: { refreshModel: true },
        updated_at: { refreshModel: true },
    };

    model(params) {
        return this.store.query('device', { ...params });
    }
}
