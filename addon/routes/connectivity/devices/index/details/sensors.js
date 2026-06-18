import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexDetailsSensorsRoute extends Route {
    @service store;

    queryParams = {
        sensors_page: { refreshModel: true },
        sensors_limit: { refreshModel: true },
        sensors_sort: { refreshModel: true },
        sensors_query: { refreshModel: true },
        sensors_status: { refreshModel: true },
        sensors_type: { refreshModel: true },
        sensors_last_reading_at: { refreshModel: true },
    };

    model(params) {
        const device = this.modelFor('connectivity.devices.index.details');
        return this.store.query('sensor', {
            page: params.sensors_page,
            limit: params.sensors_limit,
            sort: params.sensors_sort,
            query: params.sensors_query,
            status: params.sensors_status,
            type: params.sensors_type,
            last_reading_at: params.sensors_last_reading_at,
            device_uuid: device.id,
        });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.device = this.modelFor('connectivity.devices.index.details');
    }
}
