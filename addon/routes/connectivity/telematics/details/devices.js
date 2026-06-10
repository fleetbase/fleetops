import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsDevicesRoute extends Route {
    @service store;

    queryParams = {
        page: { refreshModel: true },
        limit: { refreshModel: true },
        sort: { refreshModel: true },
        query: { refreshModel: true },
        status: { refreshModel: true },
        provider: { refreshModel: true },
        attachment_state: { refreshModel: true },
        device_id: { refreshModel: true },
    };

    model(params) {
        const telematic = this.modelFor('connectivity.telematics.details');
        return this.store.query('device', {
            ...params,
            telematic_uuid: telematic.id,
        });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.telematic = this.modelFor('connectivity.telematics.details');
    }
}
