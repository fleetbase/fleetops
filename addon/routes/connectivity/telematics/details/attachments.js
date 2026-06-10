import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectivityTelematicsDetailsAttachmentsRoute extends Route {
    @service store;

    queryParams = {
        query: { refreshModel: true },
        status: { refreshModel: true },
        attachment_state: { refreshModel: true },
        vehicle: { refreshModel: true },
        sort: { refreshModel: true },
    };

    model(params) {
        const telematic = this.modelFor('connectivity.telematics.details');

        return this.store.query('device', {
            telematic_uuid: telematic.id,
            sort: params.sort,
        });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.telematic = this.modelFor('connectivity.telematics.details');
    }
}
