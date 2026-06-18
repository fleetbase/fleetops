import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityDevicesIndexDetailsEventsRoute extends Route {
    @service store;

    queryParams = {
        events_page: { refreshModel: true },
        events_limit: { refreshModel: true },
        events_sort: { refreshModel: true },
        events_query: { refreshModel: true },
        events_event_type: { refreshModel: true },
        events_severity: { refreshModel: true },
        events_processed: { refreshModel: true },
        events_occurred_at: { refreshModel: true },
        events_created_at: { refreshModel: true },
    };

    model(params) {
        const device = this.modelFor('connectivity.devices.index.details');
        return this.store.query('device-event', {
            page: params.events_page,
            limit: params.events_limit,
            sort: params.events_sort,
            query: params.events_query,
            event_type: params.events_event_type,
            severity: params.events_severity,
            processed: params.events_processed,
            occurred_at: params.events_occurred_at,
            created_at: params.events_created_at,
            device_uuid: device.id,
        });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.device = this.modelFor('connectivity.devices.index.details');
    }
}
