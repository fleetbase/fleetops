import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsEventsRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        event_type: { refreshModel: true },
        severity: { refreshModel: true },
        device_uuid: { refreshModel: true },
        provider: { refreshModel: true },
        processed: { refreshModel: true },
        occurred_at: { refreshModel: true },
        created_at: { refreshModel: true },
    };

    model(params) {
        const telematic = this.modelFor('connectivity.telematics.details');
        return this.store.query('device-event', {
            ...params,
            telematic: telematic.id,
        });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.telematic = this.modelFor('connectivity.telematics.details');
    }
}
